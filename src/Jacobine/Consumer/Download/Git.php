<?php
/**
 * This file is part of the Jacobine package.
 *
 * (c) Andreas Grunwald <andygrunwald@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jacobine\Consumer\Download;

use Jacobine\Consumer\ConsumerAbstract;
use Jacobine\Helper\ProcessFactory;
use Symfony\Component\Process\ProcessUtils;

/**
 * Class Git
 *
 * A consumer to download a git repository.
 *
 * TODO Refactor the git download consumer to download a single git without a database id
 *
 * Message format (json encoded):
 *  [
 *      id: ID of a gitweb record in the database to receive the git url
 *      project: Project to be analyzed. Must be a configured project in "configFile"
 *  ]
 *
 * Usage:
 *  php console analysis:consumer Download\\Git
 *
 * @package Jacobine\Consumer\Download
 * @author Andy Grunwald <andygrunwald@gmail.com>
 */
class Git extends ConsumerAbstract
{

    /**
     * Gets a description of the consumer
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Downloads a Git repository.';
    }

    /**
     * Initialize the consumer.
     * Sets the queue and routing key
     *
     * @return void
     */
    public function initialize()
    {
        parent::initialize();

        $this->setQueueOption('name', 'download.git');
        $this->enableDeadLettering();

        $this->setRouting('download.git');
    }

    /**
     * The logic of the consumer
     *
     * @param \stdClass $message
     * @return void
     */
    public function process($message)
    {
        $this->setMessage($message);
        $messageData = json_decode($message->body);

        $this->getLogger()->info('Receiving message', (array) $messageData);

        $record = $this->getGitwebFromDatabase($messageData->id);
        $context = ['id' => $messageData->id];

        // If the record does not exists in the database exit here
        if ($record === false) {
            $this->getLogger()->critical('Record does not exist in gitweb table', $context);
            $this->rejectMessage($message);
            return;
        }

        $config = $this->getConfig();
        $projectConfig = $config['Projects'][$messageData->project];
        $checkoutPath = $projectConfig['GitCheckoutPath'];
        $checkoutPath = rtrim($checkoutPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $search = ['/', '.git', '.'];
        $replace = ['_', '', '-'];
        $checkoutPath .= str_replace($search, $replace, $record['name']);

        $gitDirInCheckoutPath = $checkoutPath . DIRECTORY_SEPARATOR . '.git';

        $gitExecutable = escapeshellcmd($config['Application']['Git']['Binary']);

        /** @var \Symfony\Component\Process\Process $process */
        if (is_dir($checkoutPath) === true && is_dir($gitDirInCheckoutPath) === true) {
            $action = 'pull';
            list($process, $exception) = $this->executeGitUpdate($gitExecutable, $checkoutPath);
        } else {
            $action = 'clone';
            list($process, $exception) = $this->executeGitClone($gitExecutable, $record['git'], $checkoutPath);
        }

        if ($exception !== null || $process->isSuccessful() === false) {
            $context = $this->getContextOfCommand($process, $exception);
            $logMessage = sprintf('git %s failed', $action);
            $this->getLogger()->error($logMessage, $context);
            $this->rejectMessage($this->getMessage());
            return;
        }

        $this->acknowledgeMessage($message);

        // Adds new messages to queue: Analyze this via CVSAnalY
        $this->addFurtherMessageToQueue($messageData->project, $record['id'], $checkoutPath);

        $this->getLogger()->info('Finish processing message', (array)$messageData);
    }

    /**
     * Adds new messages to queue system to analyze the checkout with CVSAnalY
     *
     * @param string $project
     * @param integer $id
     * @param string $dir
     * @return void
     */
    private function addFurtherMessageToQueue($project, $id, $dir)
    {
        $config = $this->getConfig();
        $projectConfig = $config['Projects'][$project];

        $message = [
            'project' => $project,
            'gitwebId' => $id,
            'checkoutDir' => $dir
        ];

        $this->getMessageQueue()->sendSimpleMessage($message, $projectConfig['RabbitMQ']['Exchange'], 'analysis.cvsanaly');
    }

    /**
     * Updates a existing git clone
     *
     * @param string $git
     * @param string $checkoutPath
     * @return array
     */
    private function executeGitUpdate($git, $checkoutPath)
    {
        $context = [
            'dir' => $checkoutPath
        ];
        $this->getLogger()->info('Updating git repository', $context);

        // Empty repositories must not have a master branch
        if ($this->hasRepositoryAMasterBranch($git, $checkoutPath) === true) {
            $this->getLogger()->info('"master" branch detected, pull it!', $context);

            $command = $git . ' checkout master';
            $this->executeGitCommand($command, $checkoutPath);

            $command = $git . ' pull';
            $commandReturn = $this->executeGitCommand($command, $checkoutPath);

        } else {
            $logMessage = 'No "master" branch detected (checkout path "%s")';
            $logMessage = sprintf($logMessage, $checkoutPath);
            $this->getLogger()->error($logMessage, $context);
            $commandReturn = [
                null,
                new \RuntimeException($logMessage, 1396805966)
            ];
        }

        return $commandReturn;
    }

    /**
     * Checks if the git repository got a master branch
     *
     * @param string $git
     * @param string|null $checkoutPath The working directory to use the working dir of the current PHP process
     * @return bool
     */
    private function hasRepositoryAMasterBranch($git, $checkoutPath)
    {
        $result = false;
        $command = $git . ' branch';

        /** @var \Symfony\Component\Process\Process $process */
        list($process, $exception) = $this->executeGitCommand($command, $checkoutPath);
        if ($exception !== null) {
            $context = [
                'command' => $process->getCommandLine(),
                'code' => (($exception instanceof \Exception) ? $exception->getCode(): 0),
                'message' => (($exception instanceof \Exception) ? $exception->getMessage(): '')
            ];
            $this->getLogger()->error('git branch of ' . __METHOD__ . ' failed', $context);
            return $result;
        }

        $output = $process->getOutput();
        $output = explode(chr(10), $output);

        foreach ($output as $branchName) {
            // Remove the "*" which means that the current branch is chosen
            $branchName = str_replace('*', '', $branchName);
            $branchName = trim($branchName);
            if ($branchName == 'master') {
                $result = true;
                break;
            }
        }

        return $result;
    }

    /**
     * Clones a git repository
     *
     * @todo clone all branches http://stackoverflow.com/questions/67699/how-do-i-clone-all-remote-branches-with-git
     *
     * @param string $git
     * @param string $repository
     * @param string $checkoutPath
     * @return array
     */
    private function executeGitClone($git, $repository, $checkoutPath)
    {
        $context = [
            'git' => $repository,
            'dir' => $checkoutPath
        ];
        $this->getLogger()->info('Checkout git repository', $context);

        mkdir($checkoutPath, 0744, true);

        $repository = ProcessUtils::escapeArgument($repository);
        $checkoutPath = ProcessUtils::escapeArgument($checkoutPath);
        $command = $git . ' clone --recursive ' . $repository . ' ' . $checkoutPath;

        return $this->executeGitCommand($command, null);
    }

    /**
     * Receives a single gitweb record of the database
     *
     * @param integer $id
     * @return bool|array
     */
    private function getGitwebFromDatabase($id)
    {
        $fields = ['id', 'name', 'git'];
        $rows = $this->getDatabase()->getRecords($fields, 'gitweb', ['id' => $id], '', '', 1);

        $row = false;
        if (count($rows) === 1) {
            $row = array_shift($rows);
            unset($rows);
        }

        return $row;
    }

    /**
     * Executes a single command for git consumer
     *
     * TODO Extract this piece of code (+ error logging) into a own method
     *      The other consumers has to be adjusted again
     *
     * @param string $command
     * @param string|null $checkoutPath The working directory to use the working dir of the current PHP process
     * @return array [
     *                  0 => Symfony Process object,
     *                  1 => Exception if one was thrown otherwise null
     *               ]
     */
    private function executeGitCommand($command, $checkoutPath)
    {
        $timeout = null;
        $processFactory = new ProcessFactory();
        $process = $processFactory->createProcess($command, $timeout, $checkoutPath);

        $exception = null;
        try {
            $process->run();
        } catch (\Exception $exception) {
            // This catch section is empty, because we got an error handling in the caller area
            // We check not only the exception. We use the result command of the process as well
        }

        return [$process, $exception];
    }
}
