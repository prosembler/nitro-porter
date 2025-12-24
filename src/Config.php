<?php

namespace Porter;

use http\Exception;

class Config
{
    private static ?self $instance = null;

    /** @var mixed[] */
    protected array $config = [
        'debug' => false,
        'test_alias' => 'test',
        'connections' => [],
    ];

    /**
     * Make it a singleton; there's only 1 config.
     */
    public static function getInstance(): self
    {
        if (self::$instance == null) {
            self::$instance = new Config();
        }
        return self::$instance;
    }

    /**
     * @param mixed[] $config
     */
    public function set(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Get all connections available.
     *
     * @return mixed[]
     */
    public function getConnections(): array
    {
        return $this->config['connections'];
    }

    /**
     * @param string $key
     * @return ?string
     */
    public function get(string $key): ?string
    {
        // Only allow prefixed keys to be accessed directly.
        if (!in_array(substr($key, 0, 6), ['option', 'source', 'target', 'output', 'input_'])) {
            trigger_error('Config access must use allowed prefix.');
        }
        return $this->config[$key] ?? null;
    }

    /**
     * Whether debug mode is enabled in the config.
     *
     * @return bool
     */
    public function debugEnabled(): bool
    {
        return $this->config['debug'] ?? false;
    }

    /**
     * Get designated test connection.
     *
     * @return mixed[]
     * @throws \Exception
     */
    public function getTestConnection(): array
    {
        if (!isset($this->config['test_alias'])) {
            trigger_error('Config must include `test_alias` key to run tests.');
        }
        return $this->getConnectionAlias($this->config['test_alias']);
    }

    /**
     * Get config data for a connection by its alias.
     *
     * @param string $alias
     * @return mixed[]
     * @throws \Exception
     */
    public function getConnectionAlias(string $alias): array
    {
        $result = [];
        foreach ($this->config['connections'] as $connection) {
            if ($alias === $connection['alias']) {
                $result = $connection;
                break;
            }
        }

        $this->validateConnectionInfo($alias, $result);

        return $result;
    }

    /**
     * Validate config has required info.
     *
     * @param string $alias
     * @param mixed[] $info
     * @throws \Exception
     */
    protected function validateConnectionInfo(string $alias, array $info): void
    {
        // Alias not in config.
        if (empty($info)) {
            //trigger_error('Config error: Alias "' . $alias . '" not found', E_USER_ERROR);
            throw new \Exception('Alias "' . $alias . '" not found in config');
        }

        // Type is required.
        if (empty($info['type'])) {
            throw new \Exception('No connection `type` for alias "' . $alias . '" in config');
        }

        // Database required fields.
        if ($info['type'] === 'database') {
            foreach (['adapter', 'host','name','user'] as $property) {
                if (!array_key_exists($property, $info)) {
                    throw new \Exception('Database `' . $property . '` missing for alias "' . $alias . '" in config');
                }
            }
        }
    }
}
