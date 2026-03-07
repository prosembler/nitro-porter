<?php

namespace Porter;

use Illuminate\Database\Connection;
use Porter\Database\DbFactory;
use Porter\Database\ResultSet;
use Staudenmeir\LaravelCte\Query\Builder;

abstract class Source extends Package
{
    /**
     * @var array Required tables, columns set per exporter
     */
    public array $sourceTables = [];

    /**
     * @var array Table structures that define the format of the intermediary export tables.
     */
    protected array $porterStructure = [];

    /**
     * @var array Table names to limit the export to. Full export is an empty array.
     */
    public array $limitedTables = [];

    /**
     * @var DbFactory Instance DbFactory
     * @deprecated
     */
    protected DbFactory $legacyDatabase;

    public function __construct(public ?Storage $inputStorage = null, public ?Storage $porterStorage = null)
    {
        $this->porterStructure = loadData('structure');
    }

    public function sourceQB(): Builder
    {
        return new Builder($this->inputStorage->getHandle());
    }

    public function porterQB(): Builder
    {
        return new Builder($this->porterStorage->getHandle());
    }

    /**
     * Provide the input database connection.
     */
    public function dbInput(): Connection
    {
        return $this->inputStorage->getHandle();
    }

    /**
     * Provide the porter database connection.
     */
    public function dbPorter(): Connection
    {
        return $this->porterStorage->getHandle();
    }

    public function addLegacySupport(DbFactory $inputDB): void
    {
        $this->legacyDatabase = $inputDB;
    }

    /**
     * @return string
     */
    public static function getCharsetTable(): string
    {
        $charset = '';
        if (isset(static::SUPPORTED['charsetTable'])) {
            $charset = static::SUPPORTED['charsetTable'];
        }
        return $charset;
    }

    /**
     * Return the requested path (without a trailing slash).
     *
     * @param string $type
     * @param bool $addFullPath
     * @return string
     */
    public function getPath(string $type, bool $addFullPath = false): string
    {
        $folder = rtrim(static::SUPPORTED[$type . 'Path'] ?? '', '/');
        if ($addFullPath && Config::getInstance()->get('source_root')) {
            $folder = rtrim(Config::getInstance()->get('source_root'), '/') . '/' . trim($folder, '/');
        }
        return $folder;
    }

    // Source: translation table PORT_{table.nameID}
    // join sourcetable ON srcID = portID
    // map normally
    public function renumber(string $table, string $column): void
    {
        // flag for export()
    }

    /**
     * Export a collection of data, usually a table.
     *
     * @param string $tableName Name of table to export. This must correspond to one of the accepted map tables.
     * @param string|Builder $query The SQL query that will fetch the data for the export.
     * @param array $map Specifies mappings, if any, between source and export where keys represent source columns
     *   and values represent Vanilla columns.
     *   If you specify a Vanilla column then it must be in the export structure contained in this class.
     *   If you specify a MySQL type then the column will be added.
     *   If you specify an array you can have the following keys:
     *      Column (the new column name)
     *      Filter (the callable function name to process the data with)
     *      Type (the MySQL type)
     */
    public function export(string $tableName, string|Builder $query, array $map = [], array $filters = []): void
    {
        if (!empty($this->limitedTables) && !in_array(strtolower($tableName), $this->limitedTables)) {
            Log::comment("Skipping table: $tableName");
            return;
        }

        // Start timer.
        $start = microtime(true);

        // Validate table for export.
        if (!array_key_exists($tableName, $this->porterStructure)) {
            Log::comment("Error: $tableName is not a valid export.");
            return;
        }

        // Run the export query only if we got raw SQL from a legacy Source.
        if (is_string($query)) {
            $data = $this->query($query);
            if (empty($data)) {
                Log::comment("Error: No data found in $tableName.");
                return;
            }
        }

        $structure = $this->porterStructure[$tableName];

        // Reconcile data structure to be written to storage.
        list($map, $legacyFilter) = $this->porterStorage->normalizeDataMap($map); // @todo Remove legacy filter usage.
        $filters = array_merge($filters, $legacyFilter);

        // Prepare the storage medium for the incoming structure.
        $this->porterStorage->prepare($tableName, $structure);

        // Store the data.
        $info = $this->porterStorage->store($tableName, $map, $structure, $data ?? $query, $filters);

        // Report.
        Log::storage('export', $tableName, microtime(true) - $start, $info['rows'], $info['memory']);
    }

    /**
     * Execute query on inputDB() connection for backwards compatibility with Source packages.
     *
     * @param string $query The sql to execute.
     * @return ResultSet|false The query cursor.
     * @deprecated Need to remove ResultSet() from the Source packages.
     * @see self::dbInput()::unprepared()
     */
    public function query(string $query): ResultSet|false
    {
        $query = str_replace(':_', $this->dbInput()->getTablePrefix(), $query); // replace prefix.
        $query = rtrim($query, ';') . ';'; // guarantee semicolon.
        return $this->legacyDatabase->getInstance()->query($query);
    }

    /**
     * Determine if an index exists in a table
     * @deprecated Builder::hasIndex()
     *
     * @param string $indexName
     * @param string $table
     * @return bool
     */
    public function indexExists($indexName, $table): bool
    {
        $result = $this->query("show index from `$table` WHERE Key_name = '$indexName'");
        return $result->nextResultRow() !== false;
    }

    /**
     * Check if the input storage schema exists.
     *
     * @param string $table The name of the table to check.
     * @param array|string $columns Column names to check.
     * @return bool Whether the table and all columns exist.
     */
    public function hasInputSchema(string $table, array|string $columns = []): bool
    {
        return $this->inputStorage->exists($table, $columns);
    }

    /**
     * Throws error if required source tables & columns are not present.
     *
     * @param array $requiredSchema Table => Columns
     */
    public function verifySource(array $requiredSchema): void
    {
        $missingTables = [];
        $missingColumns = [];

        foreach ($requiredSchema as $table => $columns) {
            if (!$this->hasInputSchema($table)) { // Table is missing.
                $missingTables[] = $table;
            } else {
                foreach ($columns as $col) {
                    if (!$this->hasInputSchema($table, $col)) { // Column is missing.
                        $missingColumns[] = $table . '.' . $col;
                    }
                }
            }
        }
        if (!empty($missingTables)) {
            trigger_error('Missing required tables: ' . implode(', ', $missingTables));
        }
        if (!empty($missingColumns)) {
            trigger_error("Missing required columns: " . implode(', ', $missingColumns));
        }
    }

    /**
     * Determine encoding of the input MySQL database & define a constant for filters.
     *
     * There's few quicker ways to corrupt your content than to pass your content through
     * PHP's HTML entity decoder (a common requirement for UGC) using the wrong encoding.
     *
     * Alt strategy: `SHOW CREATE TABLE $table;` then regex out: ` CHARSET={charset} `.
     * Or: Possibly just get collation + drop everything after first underscore.
     *
     * @param string $table Name of the database table to derive the encoding from.
     * @return string Encoding found.
     * @see HTMLDecoder()
     */
    public function getInputEncoding(string $table): string
    {
        // Manually add table prefix.
        if (!$this->hasInputSchema($table)) {
            Log::comment('Warning: No collation table found for database \'' . $this->dbInput()->getDatabaseName() .
                '\'.' . $this->dbInput()->getTablePrefix() . $table);
            return 'UTF-8';
        }
        $table = $this->dbInput()->getTablePrefix() . $table;

        // Derive the charset from the specified MySQL database table.
        $collation = $this->dbInput()
            ->select("show table status like '{$table}'")[0]->Collation;
        $charset = $this->dbInput()
            ->select("show collation like '{$collation}'")[0]->Charset ?? 'utf8mb4';
        if (\Porter\Config::getInstance()->debugEnabled()) {
            Log::comment('? Found charset: ' . $charset);
        }

        return match ($charset) {
            'latin1' => 'ISO-8859-1', // Western European
            'latin9' => 'ISO-8859-15', // Western European (adds Euro etc; not support by MySQL)
            'cp1250' => 'cp1250', // Windows, Western Europe
            default => 'UTF-8', // utf8mb4, utf8mb3, utf8
        };
    }

    /**
     * Selective exports.
     *
     * 1. Get the comma-separated list of tables and turn it into an array
     * 2. Trim off the whitespace
     * 3. Normalize case to lower
     * 4. Save to the Migration instance
     *
     * @param ?string $tables
     */
    public function limitTables(?string $tables): void
    {
        if (!empty($tables)) {
            $tables = explode(',', $tables);
            $tables = array_map('trim', $tables);
            $tables = array_map('strtolower', $tables);
            $this->limitedTables = $tables;
        }
    }
}
