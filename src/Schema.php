<?php

namespace Porter;

class Schema
{
    /**
     * Retrieve an array from named file in `/schemas`.
     */
    public static function load(string $name): array
    {
        $data = ['porter'];
        if (in_array($name, $data, true)) {
            return include(ROOT_DIR . '/schemas/' . $name . '.php');
        } else {
            return [];
        }
    }

    /**
     * Prepare a record for storage.
     *
     * Beware sensitive order of operations.
     *
     * @param array $row Record to operate on.
     * @param array $schema fieldName => type
     * @param array $map fieldName => newName
     * @param array $filters fieldName => callable|Filter
     * @return array Normalized record.
     */
    public static function normalizeRow(array $row, array $schema, array $map, array $filters): array
    {
        $row = self::filter($row, $filters);
        $row = self::map($row, $map);
        $row = self::enforceSchema($row, $schema);
        $row = self::flatten($row);
        $row = self::encode($row);
        return self::nullEmpty($row);
    }

    /**
     * Enforce which keys are present in $row to match $schema.
     */
    private static function enforceSchema(array $row, array $schema): array
    {
        // $structure['keys'] is only for prepare(); ignore here.
        unset($schema['keys']);

        // Drop columns not in the structure.
        $row = array_intersect_key($row, $schema);

        // Add missing keys.
        return array_merge(array_fill_keys(array_keys($schema), null), $row);
    }

    /**
     * Convert all empty strings to null.
     */
    private static function nullEmpty(array $row): array
    {
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
    private static function filter(array $row, array $filters): array
    {
        foreach ($filters as $columnName => $filterName) {
            if (is_callable($filterName)) {
                $row[$columnName] = $filterName($row[$columnName], $columnName, $row);
            } else {
                $filterName = '\Porter\\Filter\\' . $filterName;
                if (array_key_exists($columnName, $row) && class_exists($filterName)) {
                    $filter = new $filterName($row[$columnName], $columnName, $row);
                    if ($filter instanceof Filter) {
                        $row[$columnName] = $filter();
                    }
                }
            }
        }
        return $row;
    }

    /**
     * Rename keys as required by applying column $map to the data $row.
     *
     * Uses:
     * 1) 'src' => 'dest' — maps key `src` in $row to column `dest`. Simplest and original use.
     * 2) `src' => [] — maps array list in `src` up a level into $row ("flattens" up 1 level)
     *      Ex: API response {'foo':[],'meta':0} where 'foo' is the list to be stored, not the top-level metadata.
     * 3) `src.sub` => `dest` — maps JSON array key `sub` in $row key `src` to column `dest.
     *      Ex: ['src.name' => 'dest'] takes JSON in `src` field and gets property `name`.
     */
    private static function map(array $row, array $map): array
    {
        // @todo One of those moments I wish I had a collections library in here.
        foreach ($map as $src => $dest) {
            // Allow flattening of nested data (1 level).
            if (is_array($dest)) {
                $row = self::mapNestedData($row, $dest, $src);
                continue; // No need to map again & do not unset so raw data can be preserved.
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
     * Convert non-UTF-8 encodings to UTF-8 as needed.
     */
    private static function encode(array $row): array
    {
        return array_map(function ($value) {
            $doEncode = $value && function_exists('mb_detect_encoding') &&
                mb_detect_encoding($value) && // Verify we know the encoding at all.
                (mb_detect_encoding($value) !== 'UTF-8') &&
                (is_string($value) || is_numeric($value));
            if ($doEncode) {
                $from = mb_detect_encoding((string)$value);
                $value = mb_convert_encoding((string)$value, 'UTF-8', $from ?: null);
            }
            return $value;
        }, $row);
    }

    /**
     * Convert arrays & objects to flat text (JSON).
     */
    private static function flatten(array $row): array
    {
        foreach ($row as &$value) {
            if (is_iterable($value)) {
                $value = json_encode($value);
            }
        }
        return $row;
    }

    /**
     * Move declared keys up a level AND map to new name.
     *
     * When $map contains an array, get nested columns and promote to the top-level of the row.
     *
     * @param array $row
     * @param array $submap Operates like $map, but for the nested values.
     * @param string $columnName In $row
     * @return array
     */
    private static function mapNestedData(array $row, array $submap, string $columnName): array
    {
        foreach ($submap as $src => $dest) {
            if (isset($row[$columnName][$src])) {
                $row[$dest] = $row[$columnName][$src];
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
     * @deprecated
     * @param array $dataMap
     * @return array $map and $filter lists
     */
    public static function normalizeDataMap(array $dataMap): array
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
}
