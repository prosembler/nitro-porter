<?php

namespace Porter;

use Staudenmeir\LaravelCte\Query\Builder;

abstract class Origin extends Package
{
    /** @var array */
    protected array $config = [];

    /**
     * @throws \Exception
     */
    public function __construct(
        protected Storage\Https $inputStorage, // Where the source data is from (read-only).
        protected Storage\Database $outputStorage, // Where the data is being written.
        string $connectionAlias,
    ) {
        $this->config = Config::getInstance()->getConnectionAlias($connectionAlias);
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
        list($response, $headers) = $this->inputStorage->get($endpoint, $query);
        $split_reply = microtime(true);

        // Discard the rest of the response if we only want a key's contents.
        if (!empty($key)) {
            $response = $response[$key];
        }

        // Store the data.
        $info = $this->outputStorage->store($tableName, $map, $fields, $response, []);

        // Get first/last records for downstream logic.
        $info['last'] = end($response);
        $info['first'] = reset($response);
        $info['headers'] = $headers;
        $info['api_time'] = $split_reply - $split_send;
        $info['pull_time'] = microtime(true) - $start;

        // Report.
        Log::storage('pull', $tableName, $info['pull_time'], count($response), $info['memory']);

        return $info;
    }
}
