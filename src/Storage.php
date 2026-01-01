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
     * @return array Information about the results.
     */
    abstract public function store(
        string $name,
        array $map,
        array $structure,
        $data,
        array $filters
    ): array;

    /**
     * @param string $name
     * @param array $structure The final, combined structure to be written.
     */
    abstract public function prepare(string $name, array $structure): void;

    abstract public function begin(): void;

    abstract public function end(): void;

    abstract public function exists(string $resourceName = '', array $structure = []): bool;

    abstract public function stream(array $row, array $structure, bool $final = false): void;

    abstract public function getHandle(): mixed;

    /**
     * Prepare a row of data for storage.
     *
     * @param array $row Data to operate on.
     * @param array $fields [fieldName => type]
     * @param array $map [fieldName => newName]
     * @param array $filters [fieldName => callable]
     * @return array
     */
    public function normalizeRow(array $row, array $fields, array $map, array $filters): array
    {
        // $fields['keys'] is only for prepare(); ignore here.
        unset($fields['keys']);

        // Apply callback filters.
        $row = $this->filterData($row, $filters);

        // Rename data keys for the target.
        $row = $this->mapData($row, $map);

        // Drop columns not in the structure.
        $row = array_intersect_key($row, $fields);

        // Add missing keys.
        $row = array_merge(array_fill_keys(array_keys($fields), null), $row);

        // Convert arrays & objects to text (JSON).
        $row = $this->flattenData($row);

        // Fix encoding as needed.
        $row = $this->fixEncoding($row);

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
            // Allow flattening 1 level. @todo Make recursive.
            if (is_array($dest)) {
                foreach ($dest as $old => $new) {
                    if (isset($row[$src][$old])) {
                        $row[$new] = $row[$src][$old]; // Move value up a level.
                    }
                }
                unset($row[$src]); // Remove column that was an array value.
                continue; // No need to map again.
            }

            // Simple-map remaining values.
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

    /**
     * Convert arrays & objects to flat text.
     *
     * @param array $row
     * @return array
     */
    protected function flattenData(array $row): array
    {
        foreach ($row as &$value) {
            if (is_iterable($value)) {
                $value = json_encode($value);
            }
        }
        return $row;
    }
}
