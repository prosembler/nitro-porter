<?php

namespace Porter;

use Illuminate\Database\Query\Builder;
use Porter\Database\ResultSet;

class Storage
{
    /**
     * Software-specific import process.
     *
     * @param string $name Name of the data chunk / table to be written.
     * @param array $map Origin -> Input names
     * @param array $structure Name -> type
     * @param ResultSet|Builder|array $data
     * @param array $filters Name -> callable
     * @return StorageInfo Information about the results.
     */
    public function store(
        string $name,
        array $map,
        array $structure,
        ResultSet|Builder|array $data,
        array $filters
    ): StorageInfo {
        $info = new StorageInfo(
            startTime: microtime(true),
        );
        if (is_array($data)) {
            // Iterate on API data.
            foreach ($data as $row) {
                $row = Schema::normalizeRow((array)$row, $structure, $map, $filters);
                $info = $this->stream($row, $info);
            }
        } elseif (is_a($data, '\Porter\Database\ResultSet')) {
            // Iterate on @deprecated ResultSet.
            while ($row = $data->nextResultRow()) {
                $row = Schema::normalizeRow($row, $structure, $map, $filters);
                $info = $this->stream($row, $info);
            }
        } elseif (is_a($data, '\Illuminate\Database\Query\Builder')) {
            // Use the Builder to process results one at a time.
            foreach ($data->cursor() as $row) { // Using `chunk()` takes MUCH longer to process.
                $row = Schema::normalizeRow((array)$row, $structure, $map, $filters);
                $info = $this->stream($row, $info);
            }
        }
        $info = $this->stream([], $info, true); // Insert remaining records.

        return new StorageInfo(
            name: $name,
            memory: $info->memory !== 0 ? $info->memory : memory_get_usage(),
            rows: $info->rows,
            startTime: $info->startTime,
            endTime: $info->endTime,
        );
    }

    /**
     * Once per $resourceName, prior to store() being used.
     * @param string $resourceName
     * @param array $structure The final, combined structure to be written.
     */
    public function prepare(string $resourceName, array $structure): void
    {
        // noop
    }

    /** Once before Storage is first used. */
    public function begin(): void
    {
        // noop
    }

    /** Once after Storage is done being used. */
    public function end(): void
    {
        // noop
    }

    /** Whether $resourceName exists, and optionally contains $structure. */
    public function exists(string $resourceName = '', array $schema = [], array $keys = []): bool
    {
        return false;
    }

    /** Send one record for storage at a time. */
    public function stream(
        array $row,
        ?StorageInfo $info = null,
        bool $final = false
    ): StorageInfo {
        throw new \LogicException('Not implemented');
    }

    /** Retrieve a reference to the underlying storage method library. */
    public function getHandle(): mixed
    {
        throw new \LogicException('Not implemented');
    }
}
