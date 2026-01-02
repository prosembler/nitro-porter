<?php

namespace Porter;

use Staudenmeir\LaravelCte\Query\Builder;

abstract class Origin extends Package
{
    /** @var Storage\Database Where the data is being written. */
    protected Storage\Database $output;

    /** @var Storage\Https Where the source data is from (read-only). */
    protected Storage\Https $input;

    /** @var array */
    protected array $config = [];

    /**
     * @param string $connectionAlias
     * @param Storage\Https $input
     * @param Storage\Database $output
     * @throws \Exception
     */
    public function __construct(string $connectionAlias, Storage\Https $input, Storage\Database $output)
    {
        $this->output = $output;
        $this->input = $input;
        $this->config = Config::getInstance()->getConnectionAlias($connectionAlias);
    }

    /**
     * Provide a query builder for the output database.
     * @internal For `Origin` packages.
     * @return Builder
     */
    protected function outputQB(): Builder
    {
        return new Builder($this->output->getHandle());
    }

    /**
     * @param string $endpoint
     * @param array $fields
     * @param string $tableName
     * @param array $query
     * @param ?string $key Response array key of data to be stored (contains $fields) or null if top-level.
     * @param array $map
     * @return array $info from store()
     * @see Migration::import() for comparison.
     */
    protected function pull(
        string $endpoint,
        array $fields,
        string $tableName,
        array $query = [],
        ?string $key = null,
        array $map = []
    ): array {
        // Start timer.
        $start = microtime(true);

        // Prepare the storage medium for the incoming structure.
        $this->output->protectTable($tableName); // Do not reset data from origins every run.
        $this->output->ignoreTable($tableName);  // Allow duplicate inserts.
        $this->output->prepare($tableName, $fields);

        // Retrieve data from the origin.
        $split_send = microtime(true);
        list($response, $headers) = $this->input->get($endpoint, $query);
        $split_reply = microtime(true);

        // Drop top level & use key only.
        $data = ($key && isset($response[$key])) ? (array)$response[$key] : $response;

        // Store the data.
        $this->output->ignoreTable($tableName); // Auto-append rather than duplicating.
        $info = $this->output->store($tableName, $map, $fields, $data, []);

        // Get first/last records for downstream logic.
        $info['last'] = end($response);
        $info['first'] = reset($response);
        $info['headers'] = $headers;
        $info['api_time'] = $split_reply - $split_send;
        $info['pull_time'] = microtime(true) - $start;

        // Report.
        Log::storage('pull', $tableName, $info['pull_time'], count($data), $info['memory']);

        return $info;
    }
}
