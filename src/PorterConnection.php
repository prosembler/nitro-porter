<?php

namespace Porter;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use MongoDB\Client;
use MongoDB\Database;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Manages a single connection to a data source or target, like a database or API.
 */
class PorterConnection
{
    /** @var array Valid values for $type. */
    public const ALLOWED_TYPES = ['database', 'files', 'api', 'mongodb'];

    protected string $type = 'database';

    protected string $alias = '';

    protected array $info = [];

    /** @var Connection|HttpClientInterface|Database Data connection. */
    protected Connection|HttpClientInterface|Database $connection;

    public Capsule $capsule;

    /**
     * If no connect alias is give, initiate a test connection.
     *
     * @param string $alias
     * @param string $prefix
     * @throws \Exception
     */
    public function __construct(string $alias = '', string $prefix = '')
    {
        if (!empty($alias)) {
            $info = Config::getInstance()->getConnectionAlias($alias);
            $this->alias = $alias; // Provided alias.
        } else {
            $info = Config::getInstance()->getTestConnection();
            $this->alias = $info['alias']; // Test alias from config.
        }

        $this->setInfo($info);
        $this->setType($info['type']);

        // Setup the connection.
        if ($info['type'] === 'database') {
            $this->setupDatabase($info, $prefix);
        } elseif ($info['type'] === 'api') {
            $this->setupHttp($info);
        } elseif ($info['type'] === 'mongodb') {
            $this->setupMongo($info);
        }
    }

    public function setType(string $type): void
    {
        if (in_array($type, self::ALLOWED_TYPES)) {
            $this->type = $type;
        }
    }

    public function setInfo(array $info): void
    {
        $this->info = $info;
    }

    public function getInfo(): array
    {
        return $this->info;
    }

    /**
     * @return array
     */
    public function getAllInfo(): array
    {
        return $this->info;
    }

    /**
     * @return string
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the current DBM connection.
     *
     * @return Connection|HttpClientInterface|Database
     */
    public function connection(): Connection|HttpClientInterface|Database
    {
        return $this->connection;
    }

    /**
     * Get a new DBM connection.
     *
     * @return Connection
     */
    protected function newDatabaseConnection(): Connection
    {
        $connection = $this->capsule->getConnection($this->alias);

       //if ($this->alias === 'discourse') {
        //echo "\n\nyes hello\n\n"; var_dump($connection); echo "\n\nyes goodbye\n\n";  }

        if ($connection->getDriverName() === 'mysql') {
            $this->optimizeMySQL($connection);
        }

        return $connection;
    }

    /**
     * Map keys from our config to Illuminate's.
     * @param array $config
     * @return array
     * @deprecated
     */
    protected function translateDatabaseConfig(array $config): array
    {
        // Valid keys: driver, host, database, username, password, charset, collation, prefix
        $config['driver'] = $config['driver'] ?? $config['adapter'];
        $config['database'] = $config['database'] ?? $config['name'];
        $config['username'] = $config['username'] ?? $config['user'];
        $config['password'] = $config['password'] ?? $config['pass'];
        //$config['strict'] = false;

        return $config;
    }

    /**
     * Perform MySQL-specific connection optimizations.
     *
     * @param Connection $connection
     * @return Connection
     */
    protected function optimizeMySQL(Connection $connection): Connection
    {
        // Always disable data integrity checks.
        $connection->unprepared("SET foreign_key_checks = 0");
        $connection->unprepared("SET unique_checks = 0");

        // Set the timezone to UTC. Avoid named timezones because they may not be loaded.
        $connection->unprepared("SET time_zone = '+00:00'");

        // Log all queries if debug mode is enabled.
        if (\Porter\Config::getInstance()->debugEnabled()) {
            // See ${hostname}.log in datadir (find with `SHOW GLOBAL VARIABLES LIKE 'datadir'`)
            $connection->unprepared("SET GLOBAL general_log = 1");
        }

        return $connection;
    }

    /**
     * Setup Illuminate Database instance.
     *
     * @param array $info
     * @param string $prefix
     */
    protected function setupDatabase(array $info, string $prefix): void
    {
        $capsule = new Capsule();
        $capsule->addConnection($this->translateDatabaseConfig($info), $info['alias']);
        $this->capsule = $capsule;
        $this->connection = $this->newDatabaseConnection();
        // Set prefix after connection is generated.
        $this->connection()->setTablePrefix($prefix);
    }

    /**
     * Setup Symfony HttpClient instance.
     *
     * @param array $info
     */
    protected function setupHttp(array $info): void
    {
        // Allowlist http-clients config options.
        $info = array_intersect_key($info, array_flip(['base_uri', 'extra']));
        $this->connection = HttpClient::create()->withOptions($info);
    }

    /**
     * Setup MongoDB client and select the target database.
     *
     * @param array $info
     */
    protected function setupMongo(array $info): void
    {
        // Build a connection string, including credentials only when provided.
        $auth = '';
        if (!empty($info['username'])) {
            $auth = rawurlencode($info['username']) . ':' . rawurlencode($info['password'] ?? '') . '@';
        }
        $uri = 'mongodb://' . $auth . $info['host'] . ':' . ($info['port'] ?? '27017');
        $this->connection = new Client($uri)->getDatabase($info['database']);
    }
}
