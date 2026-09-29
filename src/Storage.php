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
        $start = microtime(true);
        $info = new StorageInfo(
            startTime: $start,
        );
        if (is_array($data)) {
            // Iterate on API data.
            foreach ($data as $row) {
                $this->outputTimer($name, $start);
                $row = Schema::normalizeRow((array)$row, $structure, $map, $filters);
                $info = $this->stream($row, $info);
            }
        } elseif (is_a($data, '\Porter\Database\ResultSet')) {
            // Iterate on @deprecated ResultSet.
            while ($row = $data->nextResultRow()) {
                $this->outputTimer($name, $start);
                $row = Schema::normalizeRow($row, $structure, $map, $filters);
                $info = $this->stream($row, $info);
            }
        } elseif (is_a($data, '\Illuminate\Database\Query\Builder')) {
            // Use the Builder to process results one at a time.
            if (defined('PORTER_STORAGE_DUMPSQL')) {
                Log::comment("\n[SQL] " . $data->toSql());
            }
            foreach ($data->cursor() as $row) { // Using `chunk()` takes MUCH longer to process.
                $this->outputTimer($name, $start);
                $row = Schema::normalizeRow((array)$row, $structure, $map, $filters);
                $info = $this->stream($row, $info);
            }
        }
        $info = $this->stream([], $info, true); // Insert remaining records.
        $this->outputTimer($name, 0); // Reset the table timer (in case of second batch).

        return new StorageInfo(
            name: $name,
            memory: $info->memory !== 0 ? $info->memory : memory_get_usage(),
            rows: $info->rows,
            startTime: $info->startTime,
            endTime: $info->endTime,
        );
    }

    /**
     * Output dots to terminal every 5 seconds to indicate we haven't stalled.
     */
    public function outputTimer(string $name, float|int $start): void
    {
        static $timeCheck = [];
        if (0 === $start) { // Reset the table timer.
            unset($timeCheck[$name]);
            return;
        }
        $delta = floor(microtime(true) - $start);
        // Start progress output after 6 seconds, adding a dot every ~2 thereafter.
        if (!isset($timeCheck[$name][$delta]) && $delta % 2 === 0 && $delta > 5) {
            $output = isset($timeCheck[$name]) ? ' .' : "\n[$name in progress]";
            $timeCheck[$name][$delta] = 1;
            echo $output;
        }
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
