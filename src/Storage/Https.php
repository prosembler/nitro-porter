<?php

namespace Porter\Storage;

use Illuminate\Database\Query\Builder;
use Porter\ConnectionManager;
use Porter\Database\ResultSet;
use Porter\Migration;
use Porter\Storage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Https extends Storage
{
    /**
     * @var ConnectionManager
     */
    protected ConnectionManager $connectionManager;

    /**
     * @param ConnectionManager $c
     */
    public function __construct(ConnectionManager $c)
    {
        $this->connectionManager = $c;
    }

    /**
     * @param string $name Name of the data chunk / table to be written.
     * @param array $map
     * @param array $structure
     * @param ResultSet|Builder $data
     * @param array $filters
     * @param Migration $port
     * @return array Information about the results.
     */
    public function store(string $name, array $map, array $structure, $data, array $filters, Migration $port): array
    {
        return [];
    }

    /**
     * @param string $name
     * @param array $structure The final, combined structure to be written.
     */
    public function prepare(string $name, array $structure): void
    {
        //
    }

    public function begin(): void
    {
        //
    }

    public function end(): void
    {
        //
    }

    /**
     * @param string $resourceName
     * @param array $structure
     * @return bool
     */
    public function exists(string $resourceName = '', array $structure = []): bool
    {
        return false;
    }

    /**
     * @param array $row
     * @param array $structure
     * @param bool $final
     */
    public function stream(array $row, array $structure, bool $final = false): void
    {
        //
    }

    /**
     * @return HttpClientInterface
     */
    public function getHandle(): HttpClientInterface
    {
        return $this->connectionManager->connection();
    }
}
