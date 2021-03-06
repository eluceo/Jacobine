<?php
/**
 * This file is part of the Jacobine package.
 *
 * (c) Andreas Grunwald <andygrunwald@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jacobine\Helper;

/**
 * Class Database
 *
 * Database abstraction.
 * The connections will be created by DatabaseFactory class.
 * This class offers the basic abstraction for select, insert, update and delete statements.
 * All statements are executed via PreparedStatements to avoid possible sql injections.
 *
 * @package Jacobine\Helper
 * @author Andy Grunwald <andygrunwald@gmail.com>
 */
class Database
{

    /**
     * Database handle
     *
     * @var \PDO
     */
    protected $handle = null;

    /**
     * Database factory
     *
     * @var \Jacobine\Helper\DatabaseFactory
     */
    protected $factory = null;

    /**
     * Last used statement
     *
     * @var \PDOStatement
     */
    protected $lastStatement = null;

    /**
     * Credentials for connecting with the database
     *
     * @var array
     */
    protected $credentials = [];

    /**
     * Constructor to initialize the database connection
     *
     * @param DatabaseFactory $factory
     * @param string $host
     * @param integer $port
     * @param string $username
     * @param string $password
     * @param string $database
     * @return \Jacobine\Helper\Database
     */
    public function __construct(DatabaseFactory $factory, $host, $port, $username, $password, $database)
    {
        $this->factory = $factory;
        // TODO make database driver configurable (via config)
        $this->connect('mysql', $host, $port, $username, $password, $database);
    }

    /**
     * Connects to the database
     *
     * @param string $driverName
     * @param string $host
     * @param integer $port
     * @param string $username
     * @param string $password
     * @param string $database
     * @return void
     */
    protected function connect($driverName, $host, $port, $username, $password, $database)
    {
        $this->reconnect($driverName, $host, $port, $username, $password, $database);
        $this->setCredentials($driverName, $host, $port, $username, $password, $database);
    }

    /**
     * Reconnects to the database
     *
     * @param string $driverName
     * @param string $host
     * @param integer $port
     * @param string $username
     * @param string $password
     * @param string $database
     * @return void
     */
    protected function reconnect($driverName, $host, $port, $username, $password, $database)
    {
        $this->handle = $this->factory->create($driverName, $host, $port, $username, $password, $database);
    }

    /**
     * Sets the database connection credentials
     *
     * @param string $driverName
     * @param string $host
     * @param integer $port
     * @param string $username
     * @param string $password
     * @param string $database
     * @return void
     */
    private function setCredentials($driverName, $host, $port, $username, $password, $database)
    {
        $this->credentials = [
            'driver' => $driverName,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'database' => $database
        ];
    }

    /**
     * Sets the last used PDOStatement
     * Returns if the database connection was reconnected or not.
     * True = reconnected
     * False = not reconnected
     *
     * @param \PDOStatement $statement
     * @return bool
     * @throws \Exception
     */
    protected function setLastStatement(\PDOStatement $statement)
    {
        $this->lastStatement = $statement;
        $errorInfo = $statement->errorInfo();

        // MySQL server has gone away ... automatic reconnect
        // The connection can be lost if the mysql.connection_timeout or default_socket_timeout is to low.
        // This is a RabbitMQ consumer library, this means this are long running processes.
        // In the long time the connection can be lost.
        // This can be the case if the download of a HTTP package is to slow (e.g. due to a slow bandwith).
        if ($errorInfo[1] && $errorInfo[1] == 2006) {
            $credentials = $this->credentials;
            $this->reconnect(
                $credentials['driver'],
                $credentials['host'],
                $credentials['host'],
                $credentials['username'],
                $credentials['password'],
                $credentials['database']
            );
            return true;

        } elseif ($errorInfo[1]) {
            $message = 'Database error: %s (%d)';
            $message = sprintf($message, $errorInfo[2], $errorInfo[1]);
            throw new \Exception($message, 1372191636);
        }

        return false;
    }

    /**
     * Gets the last statement
     *
     * @return null|\PDOStatement
     */
    protected function getLastStatement()
    {
        return $this->lastStatement;
    }

    /**
     * Get the PDO connection
     *
     * @return null|\PDO
     */
    protected function getHandle()
    {
        return $this->handle;
    }

    /**
     * Prepares the "prepared" parts for database queries.
     *
     * Identifier will be :fieldname
     *
     * @param array $parts
     * @param string $implodeGlue
     * @return array
     */
    protected function buildPreparedParts(array $parts, $implodeGlue)
    {
        $queryParts = array();
        $prepareParts = array();

        foreach ($parts as $field => $value) {
            $queryParts[] = $field . ' = :' . $field;
            $prepareParts[':' . $field] = $value;
        }

        return array(implode($implodeGlue, $queryParts), $prepareParts);
    }

    /**
     * Gets records form the database.
     * Executes a SELECT statement.
     *
     * @param array $fields An array of fields. E.g. array('*') or array('id', 'version', ...)
     * @param string $table
     * @param array $where
     * @param string $groupBy
     * @param string $orderBy
     * @param string $limit
     * @return array
     */
    public function getRecords(
        array $fields,
        $table,
        array $where = array(),
        $groupBy = '',
        $orderBy = '',
        $limit = ''
    ) {
        list($where, $prepareParts) = $this->buildPreparedParts($where, ' AND ');
        $query = '
            SELECT ' . implode(',', $fields) . '
            FROM ' . $table . '
            WHERE ' . $where;

        if ($groupBy) {
            $query .= ' GROUP BY ' . $groupBy;
        }

        if ($orderBy) {
            $query .= ' ORDER BY ' . $orderBy;
        }

        if ($limit) {
            $query .= ' LIMIT ' . $limit;
        }

        $this->executeStatement($query, $prepareParts);
        $result = $this->getLastStatement()->fetchAll(\PDO::FETCH_ASSOC);

        return $result;
    }

    /**
     * Inserts a single record into the database.
     * A prepared statement will be used.
     *
     * @param string $table
     * @param array $data
     * @throws \UnexpectedValueException
     * @return string
     */
    public function insertRecord($table, array $data)
    {
        $this->checkIfTableEmpty($table);
        $this->checkIfDataIsEmpty($data);

        $preparedValues = array();
        foreach ($data as $key => $value) {
            $preparedValues[':' . $key] = $value;
        }

        $query = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(
            ',',
            array_keys($preparedValues)
        ) . ')';
        $this->executeStatement($query, $preparedValues);

        return $this->getHandle()->lastInsertId();
    }

    /**
     * Checks if table name was given
     *
     * @param string $table
     * @throws \UnexpectedValueException
     * @return void
     */
    private function checkIfTableEmpty($table)
    {
        if (!$table) {
            throw new \UnexpectedValueException('Table must not be empty!', 1392668682);
        }
    }

    /**
     * Checks if incoming data is empty
     *
     * @param array $data
     * @throws \UnexpectedValueException
     * @return void
     */
    private function checkIfDataIsEmpty(array $data)
    {
        if ($data === []) {
            throw new \UnexpectedValueException('Data must not be empty!', 1392668862);
        }
    }

    /**
     * Update record in the database.
     *
     * @param string $table
     * @param array $data
     * @param array $where
     * @return bool
     */
    public function updateRecord($table, array $data, array $where = array())
    {
        $this->checkIfTableEmpty($table);
        $this->checkIfDataIsEmpty($data);

        $prepareWhereParts = [];
        if ($where) {
            list($where, $prepareWhereParts) = $this->buildPreparedParts($where, ' AND ');
        }

        list($update, $prepareUpdateParts) = $this->buildPreparedParts($data, ', ');
        $prepareParts = $prepareWhereParts + $prepareUpdateParts;

        $query = '
            UPDATE ' . $table . '
            SET ' . $update;

        if ($where) {
            $query .= ' WHERE ' . $where;
        }

        $result = $this->executeStatement($query, $prepareParts);

        return $result;
    }

    /**
     * Delete record(s) from database
     *
     * @param string $table
     * @param array $where
     * @return bool
     */
    public function deleteRecords($table, array $where)
    {
        $this->checkIfTableEmpty($table);
        $this->checkIfDataIsEmpty($where);

        list($where, $prepareWhereParts) = $this->buildPreparedParts($where, ' AND ');

        $query = '
            DELETE FROM ' . $table . '
            WHERE ' . $where;

        $result = $this->executeStatement($query, $prepareWhereParts);

        return $result;
    }

    /**
     * Executes a single database statement
     *
     * @param string $query
     * @param array $preparedParts
     * @return bool
     */
    private function executeStatement($query, array $preparedParts)
    {
        $statement = $this->getHandle()->prepare($query);
        $result = $statement->execute($preparedParts);

        $reconnected = $this->setLastStatement($statement);
        if ($reconnected === true) {
            $result = $this->executeStatement($query, $preparedParts);
        }

        return $result;
    }
}
