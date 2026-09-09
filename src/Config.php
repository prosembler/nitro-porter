<?php

namespace Porter;

class Config
{
    private static ?self $instance = null;

    /** In-memory config with defaults. */
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
     * Retrieve the config from file.
     */
    public static function loadFile(): array
    {
        if (file_exists(ROOT_DIR . '/config.php')) {
            return require(ROOT_DIR . '/config.php');
        } else {
            return require(ROOT_DIR . '/config-sample.php');
        }
    }

    /**
     * Set a config value (in memory).
     */
    public function set(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Get all connections available.
     */
    public function getConnections(): array
    {
        return $this->config['connections'];
    }

    /**
     * Get a config value.
     */
    public function get(string $key): ?string
    {
        // Only allow prefixed keys to be accessed directly.
        if (!in_array(substr($key, 0, 6), ['option', 'source', 'target', 'origin', 'output', 'input_', 'porter'])) {
            trigger_error('Config access must use allowed prefix.');
        }
        return $this->config[$key] ?? null;
    }

    /**
     * Whether debug mode is enabled in the config.
     */
    public function debugEnabled(): bool
    {
        return $this->config['debug'] ?? false;
    }

    /**
     * @todo Allow 'merge' command to be passed down.
     */
    public function mergeEnabled(): bool
    {
        return false;
    }

    /**
     * Get the configured offset value for starting IDs per key.
     */
    public function getOffset(string $name): int
    {
        $valid = ['users', 'roles', 'categories', 'discussions', 'comments',
            'attachments','polls', 'polloptions', 'tags', 'badges'];
        if (!in_array($name, $valid)) {
            Log::comment('Invalid offset name: ' . $name);
            return 0;
        }
        $offsets = array_filter($this->config['offsets'], fn ($offset) => !empty($offset) ? $offset : false);
        return (!empty($offsets[$name]) && is_numeric($offsets[$name])) ? (int) $offsets[$name] : 0;
    }

    /**
     * Get designated test connection.
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
     * @throws \Exception
     */
    public function getConnectionAlias(string $alias): array
    {
        $result = [];
        foreach ($this->config['connections'] as $connection) {
            if ($alias === $connection['alias'] || strtolower($alias) === $connection['alias']) {
                $result = $connection;
                break;
            }
        }

        $this->validateConnectionInfo($alias, $result);

        return $result;
    }

    /**
     * Validate config has required info.
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
            foreach (['adapter', 'host'] as $property) {
                if (!array_key_exists($property, $info)) {
                    throw new \Exception('Database `' . $property . '` missing for alias "' . $alias . '" in config');
                }
            }
        }
    }
}
