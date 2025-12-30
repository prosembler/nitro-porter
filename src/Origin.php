<?php

namespace Porter;

use Staudenmeir\LaravelCte\Query\Builder;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

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
     * @param array $query
     * @param ?string $key Response array key of data to be stored (contains $fields) or null if top-level.
     * @param array $fields
     * @param string $tableName
     * @see Migration::import()
     */
    protected function pull(string $endpoint, array $query, ?string $key, array $fields, string $tableName): void
    {
        // Start timer.
        $start = microtime(true);

        // Prepare the storage medium for the incoming structure.
        $this->output->prepare($tableName, $fields);

        // Retrieve data from the origin.
        $response = $this->input->get($endpoint, $query);
        if ($key) { // @todo Brittle; needs API-equivalent of normalizeRow() eventually.
            $response = $response[$key];
        }

        // Store the data.
        $info = $this->output->store($tableName, [], $fields, $response, []);

        // Report.
        Log::storage('pull', $endpoint . ' > ' . $tableName, microtime(true) - $start, $info['rows'], $info['memory']);
    }
}
