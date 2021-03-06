<?php
/**
 * This file is part of the Jacobine package.
 *
 * (c) Andreas Grunwald <andygrunwald@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jacobine\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Jacobine\Helper\AMQPFactory;
use Jacobine\Helper\Database;
use Jacobine\Helper\DatabaseFactory;
use Jacobine\Helper\MessageQueue;

/**
 * Class GetTYPO3OrgCommand
 *
 * Command to get the JSON stream from http://get.typo3.org/.
 * This stream contains information about releases of the TYPO3 CMS
 * e.g. Name of release, version number, status (development, beta, stable), download url, ...
 *
 * This commands parses the JSON information, adds the various versions to the database
 * and sends one message per release to the message broker to download it :)
 *
 * Usage:
 *  php console typo3:get.typo3.org
 *
 * @package Jacobine\Command
 * @author Andy Grunwald <andygrunwald@gmail.com>
 */
class GetTYPO3OrgCommand extends Command
{

    /**
     * JSON File with all information we need
     *
     * @var string
     */
    const JSON_FILE_URL = 'http://get.typo3.org/json';

    /**
     * Message Queue routing
     *
     * @var string
     */
    const ROUTING = 'download.http';

    /**
     * Project identifier
     *
     * @var string
     */
    const PROJECT = 'TYPO3';

    /**
     * Config
     *
     * @var array
     */
    protected $config = [];

    /**
     * HTTP Client
     *
     * @var \Buzz\Browser
     */
    protected $browser;

    /**
     * Database connection
     *
     * @var \Jacobine\Helper\Database
     */
    protected $database;

    /**
     * MessageQueue connection
     *
     * @var \Jacobine\Helper\MessageQueue
     */
    protected $messageQueue;

    /**
     * Message Queue Exchange
     *
     * @var string
     */
    protected $exchange;

    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('typo3:get.typo3.org')
             ->setDescription('Queues tasks for TYPO3 CMS releases');
    }

    /**
     * Initializes the command just after the input has been validated.
     *
     * Sets up the config, HTTP client, database and message queue
     *
     * @param InputInterface $input An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     * @return void
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Config
        $this->config = Yaml::parse(CONFIG_FILE);

        // HTTP Client
        $curlClient = new \Buzz\Client\Curl();
        $curlClient->setTimeout(intval($this->config['Various']['Requests']['Timeout']));
        $this->browser = new \Buzz\Browser($curlClient);

        // Database client
        $config = $this->config['MySQL'];
        // The project is hardcoded here, because this command is special for the OpenSourceProject TYPO3
        $projectConfig = $this->config['Projects'][self::PROJECT];
        $this->exchange = $projectConfig['RabbitMQ']['Exchange'];

        $databaseFactory = new DatabaseFactory();
        // TODO Refactor this to use a config entity or an array
        $this->database = new Database($databaseFactory, $config['Host'], $config['Port'], $config['Username'], $config['Password'], $projectConfig['MySQL']['Database']);

        // Message queue client
        $config = $this->config['RabbitMQ'];

        $amqpFactory = new AMQPFactory();
        $amqpConnection = $amqpFactory->createConnection($config['Host'], $config['Port'], $config['Username'], $config['Password'], $config['VHost']);
        $this->messageQueue = new MessageQueue($amqpConnection, $amqpFactory);
    }

    /**
     * Executes the current command.
     *
     * Reads all versions from get.typo3.org/json, store them into a database
     * and add new messages to message queue to download this versions.
     *
     * @param InputInterface $input An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     * @return null|integer null or 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $versions = $this->getReleaseInformation();
        foreach ($versions as $branch => $data) {
            // $data got two keys: releases + latest
            // Sometimes, the releases key does not exists.
            // This is the case where $branch is latest_stable, latest_lts or latest_deprecated
            // We can skip this here
            // @todo update database with this information!
            if (is_array($data) === false
                || array_key_exists('releases', $data) === false
                || is_array($data['releases']) === false
            ) {
                continue;
            }

            foreach ($data['releases'] as $releaseVersion => $releaseData) {

                // Temp. fix for http://forge.typo3.org/issues/49337
                if (strpos($releaseData['url']['tar'], 'snapshot')) {
                    continue;
                }

                // Try to get the current version from the database
                $versionRecord = $this->getVersionFromDatabase($releaseVersion);

                // If the current version is not in database already, create it
                if ($versionRecord === false) {
                    $versionRecord = $this->insertVersionIntoDatabase($branch, $releaseData);
                }

                // If the current version is not downloaded yet, queue it
                if (!$versionRecord['downloaded']) {
                    $message = array(
                        'project' => self::PROJECT,
                        'versionId' => $versionRecord['id'],
                        'filenamePrefix' => 'typo3_',
                        'filenamePostfix' => '.tar.gz',
                    );

                    $this->messageQueue->sendSimpleMessage($message, $this->exchange, self::ROUTING);
                }
            }
        }

        return null;
    }

    /**
     * Stores a single version of TYPO3 into the database table 'versions'
     *
     * @param string $branch Branch version like 4.7, 6.0, 6.1, ...
     * @param array $versionData Data about the current version provided by the json file
     * @return array
     */
    private function insertVersionIntoDatabase($branch, $versionData)
    {
        $data = array(
            'branch' => $branch,
            'version' => $versionData['version'],
            'date' => $versionData['date'],
            'type' => $versionData['type'],
            'checksum_tar_md5' => $versionData['checksums']['tar']['md5'],
            'checksum_tar_sha1' => $versionData['checksums']['tar']['sha1'],
            'checksum_zip_md5' => $versionData['checksums']['zip']['md5'],
            'checksum_zip_sha1' => $versionData['checksums']['zip']['sha1'],
            'url_tar' => $versionData['url']['tar'],
            'url_zip' => $versionData['url']['zip'],
            'downloaded' => 0
        );
        $data['id'] = $this->database->insertRecord('versions', $data);
        return $data;
    }

    /**
     * Receives a single version from the database table 'versions' (if exists).
     *
     * @param string $version A version like 4.5.7, 6.0.4, ...
     * @return bool|array
     */
    private function getVersionFromDatabase($version)
    {
        $rows = $this->database->getRecords(
            array('id', 'downloaded'),
            'versions',
            array('version' => $version),
            '',
            '',
            1
        );

        $row = false;
        if (count($rows) === 1) {
            $row = array_shift($rows);
            unset($rows);
        }

        return $row;
    }

    /**
     * Downloads the json file about the versions of TYPO3
     *
     * @return array
     */
    private function getReleaseInformation()
    {
        $response = $this->browser->get(static::JSON_FILE_URL);
        /** @var \Buzz\Message\Response $response */
        if ($response->isOk() !== true) {
            return false;
        }

        $jsonContent = $response->getContent();
        return json_decode($jsonContent, true);
    }
}
