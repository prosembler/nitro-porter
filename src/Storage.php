<?php

namespace Porter;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Porter\Database\ResultSet;

abstract class Storage
{
    /**
     * Software-specific import process.
     *
     * @param string $name Name of the data chunk / table to be written.
     * @param array $map
     * @param array $structure
     * @param ResultSet|Builder $data
     * @param array $filters
     * @param Migration $port
     * @return array Information about the results.
     */
    abstract public function store(
        string $name,
        array $map,
        array $structure,
        $data,
        array $filters,
        Migration $port
    ): array;

    /**
     * @param string $name
     * @param array $structure The final, combined structure to be written.
     */
    abstract public function prepare(string $name, array $structure): void;

    abstract public function begin(): void;

    abstract public function end(): void;

    abstract public function setPrefix(string $prefix): void;

    abstract public function exists(string $tableName, array $columns = []): bool;

    abstract public function stream(array $row, array $structure): void;

    abstract public function endStream(): void;

    abstract public function getAlias(): string;

    abstract public function getConnection(): ?Connection;

    /**
     * Prepare a row of data for storage.
     *
     * @param array $map
     * @param array $structure
     * @param array $row
     * @param array $filters
     * @return array
     */
    public function normalizeRow(array $map, array $structure, array $row, array $filters): array
    {
        // Apply callback filters.
        $row = $this->filterData($row, $filters);

        // Rename data keys for the target.
        $row = $this->mapData($row, $map);

        // Fix encoding as needed.
        $row = $this->fixEncoding($row);

        // Drop columns not in the structure.
        $row = array_intersect_key($row, $structure);

        // Convert empty strings to null.
        return array_map(function ($value) {
            return ('' === $value) ? null : $value;
        }, $row);
    }

    /**
     * Apply callback filters to the data row.
     *
     * @param array $row Single row of query results.
     * @param array $filters List of column => callable.
     * @return array
     */
    public function filterData(array $row, array $filters): array
    {
        foreach ($filters as $column => $callable) {
            if (array_key_exists($column, $row)) {
                $row[$column] = call_user_func($callable, $row[$column], $column, $row);
            }
        }

        return $row;
    }

    /**
     * Apply column map to the data row to rename keys as required.
     *
     * @param array $row
     * @param array $map
     * @return array
     */
    public function mapData(array $row, array $map): array
    {
        // @todo One of those moments I wish I had a collections library in here.
        foreach ($map as $src => $dest) {
            foreach ($row as $columnName => $value) {
                if ($columnName === $src) {
                    $row[$dest] = $value; // Add column with new name.
                    if ($dest !== $columnName) {
                        unset($row[$columnName]); // Remove old column.
                    }
                }
            }
        }

        return $row;
    }

    /**
     * Fixes source datamap arrays to not be multi-dimensional.
     *
     * Splits the 'Filter' property to a new array and collapses 'Column' as the value.
     * Ignores 'Type' property and any other nonsense.
     * Rather than updating 100 lines of Source DataMaps, do this for now.
     *
     * @param array $dataMap
     * @return array $map and $filter lists
     */
    public function normalizeDataMap(array $dataMap): array
    {
        $filter = [];
        foreach ($dataMap as $source => $dest) {
            if (is_array($dest)) {
                // Collapse the value to a string.
                // This key had better be present, so letting it error if not is fine tbh.
                $dataMap[$source] = $dest['Column'];
                if (array_key_exists('Filter', $dest)) {
                    // Add to the outgoing $filter list. Can be an array $callable or a closure.
                    $filter[$source] = $dest['Filter'];
                }
            }
        }

        return [$dataMap, $filter];
    }

    /**
     * Convert non-UTF-8 encodings to UTF-8.
     *
     * @param array $row
     * @return array
     */
    public function fixEncoding(array $row): array
    {
        return array_map(function ($value) {
            $doEncode = $value && function_exists('mb_detect_encoding') &&
                mb_detect_encoding($value) && // Verify we know the encoding at all.
                (mb_detect_encoding($value) !== 'UTF-8') &&
                (is_string($value) || is_numeric($value));
            return ($doEncode) ? mb_convert_encoding($value, 'UTF-8', mb_detect_encoding($value)) : $value;
        }, $row);
    }
}
