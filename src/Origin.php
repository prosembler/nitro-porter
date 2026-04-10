<?php

namespace Porter;

use Staudenmeir\LaravelCte\Query\Builder;

abstract class Origin extends Package
{
    /** @var array */
    protected array $config = [];

    /** @var Storage\Https Where the origin data is from (read-only HTTPS). */
    protected Storage\Https $originStorage;

    /**
     * @throws \Exception
     */
    public function __construct(
        protected Storage\Database $outputStorage, // Where data is being written.
        protected Storage\Database $extractStorage, // Second connection for simultaneous read/write.
        string $connectionAlias,
    ) {
        $this->config = Config::getInstance()->getConnectionAlias($connectionAlias);
    }

    public function addHttps(Storage\Https $originStorage): void
    {
        $this->originStorage = $originStorage;
    }

    /**
     * Provide a query builder for the output database.
     * @internal For `Origin` packages.
     * @return Builder
     */
    protected function outputQB(): Builder
    {
        return new Builder($this->outputStorage->getHandle());
    }

    /**
     * @param string $endpoint
     * @param array $fields
     * @param string $tableName
     * @param string|null $key A non-null value will discard other data & use this key (only) as the data.
     * @param array $query
     * @param array $map
     * @return array $info from store()
     * @see Migration::import() for comparison.
     */
    protected function pull(
        string $endpoint,
        array $fields,
        string $tableName,
        ?string $key = null,
        array $query = [],
        array $map = []
    ): array {
        // Start timer.
        $start = microtime(true);

        // Prepare the storage medium for the incoming structure.
        $this->outputStorage->protectTable($tableName); // Do not reset data from origins every run.
        $this->outputStorage->ignoreTable($tableName);  // Allow duplicate inserts.
        $this->outputStorage->prepare($tableName, $fields);

        // Retrieve data from the origin.
        $split_send = microtime(true);
        list($content, $headers, $code) = $this->originStorage->get($endpoint, $query);
        $split_reply = microtime(true);

        // Discard the rest of the content if we only want a key's contents.
        if (!empty($key)) {
            if (!empty($content) && !empty($content[$key])) {
                $content = $content[$key];
            } else {
                Log::comment("Warning: Key '{$key}' not found in response from '{$endpoint}'.");
            }
        }

        // Store the data.
        $info = $this->outputStorage->store($tableName, $map, $fields, $content, []);

        // Add metadata for downstream logic.
        $info['content'] = $content;
        $info['last'] = end($content);
        $info['first'] = reset($content);
        $info['headers'] = $headers;
        $info['query'] = $query;
        $info['http_code'] = $code;
        $info['endpoint'] = $endpoint;
        $info['api_time'] = $split_reply - $split_send;
        $info['pull_time'] = microtime(true) - $start;
        Log::pull($tableName, $info);

        return $info;
    }

    /**
     * Extract a list to its own table.
     */
    protected function extract(string $tableName, array $fields, array $data, bool $report = false): array
    {
        if (empty($fields)) {
            return []; // May be called blindly without checking for records.
        }

        // Start timer.
        $start = microtime(true);

        // Prepare the storage medium for the incoming structure.
        $this->extractStorage->protectTable($tableName); // Do not reset data from origins every run.
        $this->extractStorage->ignoreTable($tableName);  // Allow duplicate inserts.
        $this->extractStorage->prepare($tableName, $fields);

        // Store the data.
        $info = $this->extractStorage->store($tableName, [], $fields, $data, []);

        // Report.
        if ($report) {
            Log::storage('extract', $tableName, microtime(true) - $start, count($data), $info['memory']);
        }
        return $info;
    }

    /**
     * Folder to download files into.
     *
     * @return string source_root/$name/
     */
    protected function getDownloadFolder(string $name): string
    {
        // Get the source_root.
        $srcRoot = Config::getInstance()->get('source_root');
        if (empty($srcRoot)) {
            Log::comment("No download folder defined in config (`source_root`)");
            return '';
        }

        // Build the path & return it.
        $folder = rtrim($srcRoot, '/') . '/' . trim($name, '/');
        $exists = touchFolder($folder);
        return ($exists) ? $folder . '/' : '';
    }
}
