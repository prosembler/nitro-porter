<?php

namespace Porter\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Porter\ConnectionManager;
use Porter\Log;
use Porter\Migration;
use Porter\Storage;

class Database extends Storage
{
    /** @var int How many rows to insert at once. */
    public const int INSERT_BATCH = 1000;

    /** @var int When to start reporting on incremental storage (in the logs). */
    public const int LOG_THRESHOLD = 100000;

    /** @var int Increment to report at after `REPORT_THRESHOLD` is reached; must be multiple of `INSERT_BATCH`. */
    public const int LOG_INCREMENT = 100000;

    /** @var string Prefix for the storage database. */
    protected string $prefix = '';

    /** @var string Table name currently targeted by the batcher. */
    protected string $batchTable = '';

    /** @var array List of tables that have already been reset to avoid dropping multipart import data. */
    protected array $resetTables = [];

    /** @var array List of tables to ignore errors on insert. */
    protected array $ignoreErrorsTables = [];

    /** @var ConnectionManager */
    protected ConnectionManager $connectionManager;

    /** @param ConnectionManager $c */
    public function __construct(ConnectionManager $c)
    {
        $this->connectionManager = $c;
    }

    /**
     * Retrieve a reference to the underlying storage method library.
     * @return Connection
     */
    public function getHandle(): Connection
    {
        return $this->connectionManager->connection();
    }

    /**
     * Report incremental storage for large datasets.
     *
     * @param string $name
     * @param int $rows
     */
    public function logBatchProgress(string $name, int $rows): void
    {
        if ($rows >= self::LOG_THRESHOLD && ($rows % self::LOG_INCREMENT) === 0) {
            Log::comment("inserting '" . $name . "': " . number_format($rows) . ' done...', false);
        }
    }

    /**
     * Lower-level single record insert access.
     *
     * While `store()` takes a batch and processes it, this takes 1 row at a time.
     * Created for Postscripts to have finer control over record inserts.
     * @param array $row
     * @param array $structure
     * @param array $info
     * @param bool $final Must be `true` on final call or records will be lost.
     * @return array
     */
    public function stream(array $row, array $structure, array $info = [], bool $final = false): array
    {
        $info = $this->batchInsert($row, $info, $final);
        $info['rows']++;
        return $info;
    }

    /**
     * Accept rows one at a time and batch them together for more efficient inserts.
     *
     * @param array $row Row of data to insert.
     * @param array $info
     * @param bool $final Force an insert with existing batch.
     * @return array Meta info.
     *   - memory = Bytes currently being used by the app.
     */
    private function batchInsert(array $row, array $info, bool $final = false): array
    {
        static $batch = [];
        if (!empty($row)) {
            $batch[] = $row;
        }

        // Measure highest memory usage before potential send.
        $info['memory'] = max(memory_get_usage(), $info['memory']);

        if (self::INSERT_BATCH === count($batch) || $final) {
            $this->sendBatch($batch);
            $batch = [];
        }

        // Log count.
        if (isset($info['name'])) {
            $this->logBatchProgress($info['name'], $info['rows']);
        }

        return $info;
    }

    /**
     * Insert a batch of rows into the database.
     *
     * Ignore errors if table is in `ignoreErrorsTables` list.
     *
     * @param array $batch
     */
    private function sendBatch(array $batch): void
    {
        $tableName = $this->getBatchTable();
        $action = (in_array($tableName, $this->ignoreErrorsTables)) ? 'insertOrIgnore' : 'insert';
        try {
            $this->connectionManager->connection()->table($tableName)->$action($batch);
        } catch (\Illuminate\Database\QueryException $e) {
            echo "\n\nBatch insert error: " . substr($e->getMessage(), 0, 500);
            echo "\n[...]\n" . substr($e->getMessage(), -300) . "\n";
        }
    }

    /**
     * Add a table name to the list for ignoring insert errors. Adds the prefix for you.
     *
     * @param string $tableName
     */
    public function ignoreTable(string $tableName): void
    {
        $this->ignoreErrorsTables[] = $tableName;
    }

    /**
     * Do not reset this table.
     *
     * @param string $tableName
     */
    public function protectTable(string $tableName): void
    {
        $this->resetTables[] = $tableName;
    }

    /**
     * @param string $tableName
     * @return bool Whether table is protected.
     */
    private function isProtectedTable(string $tableName): bool
    {
        return in_array($tableName, $this->resetTables);
    }

    /**
     * Set table name that sendBatch() will target.
     *
     * @param string $tableName
     */
    private function setBatchTable(string $tableName): void
    {
        $this->batchTable = $this->prefix . $tableName;
    }

    /**
     * Get table name that sendBatch() will target.
     *
     * @return string
     */
    private function getBatchTable(): string
    {
        return $this->batchTable;
    }

    /**
     * Create fresh table for storage. Use prefix.
     *
     * @param string $resourceName
     * @param array $structure
     */
    public function prepare(string $resourceName, array $structure): void
    {
        // Only drop/truncate tables that already exist if they're not protected.
        if (!$this->exists($resourceName) || !$this->isProtectedTable($resourceName)) {
            $this->createOrUpdateTable($this->prefix . $resourceName, $structure);
        }
        $this->protectTable($resourceName); // Avoid drop/truncate a table after it's prepared.
        $this->setBatchTable($resourceName);
    }

    /**
     * Create a new table if it doesn't already exist.
     *
     * @param string $name
     * @param array $structure
     */
    public function createOrUpdateTable(string $name, array $structure): void
    {
        $dbm = $this->connectionManager->dbm->getConnection($this->connectionManager->getAlias());
        $schema = $dbm->getSchemaBuilder();
        if ($this->exists($name)) {
            // Empty the table if it already exists & is not protected.
            // Foreign key check must be disabled or MySQL throws error.
            if (!$this->isProtectedTable($name)) {
                $dbm->unprepared("SET foreign_key_checks = 0");
                $dbm->query()->from($name)->truncate();
            }

            // Add any missing columns.
            // To do this, removing existing columns from $structure & build a schema closure.
            $existingStructure = $schema->getColumnListing($name);
            foreach ($existingStructure as $existingColumn) {
                unset($structure[$existingColumn]);
            }
            // Don't set any keys because I don't know how to easily get that list & it's probably fine.
            unset($structure['keys']);
            $schema->table($name, $this->getTableStructureClosure($structure));
        } else {
            // Create table if it does not.
            $schema->create($name, $this->getTableStructureClosure($structure));
        }
    }

    /**
     * Whether the requested table & columns exist.
     *
     * @param string $resourceName
     * @param array $structure
     * @return bool
     * @see Migration::hasInputSchema()
     */
    public function exists(string $resourceName = '', array $structure = []): bool
    {
        $schema = $this->connectionManager->connection()->getSchemaBuilder();
        if (empty($structure)) {
            // No columns requested.
            return $schema->hasTable($resourceName);
        }
        // Table must exist and columns were requested.
        return $schema->hasTable($resourceName) && $schema->hasColumns($resourceName, $structure);
    }

    /**
     * Converts a simple array of Column => Type into a callable table structure.
     *
     * Ideally, we'd just pass structures in the correct format to start with.
     * Unfortunately, this isn't greenfield software, and today it's less-bad
     * to write this method than to try to convert thousands of these manually.
     * @see https://laravel.com/docs/9.x/migrations#creating-columns
     *
     * @param array $tableInfo Keys are column names, values are MySQL data types.
     *      A special key 'keys' can be passed to define database columns.
     * @return callable Closure defining a single Illuminate Database table.
     */
    private function getTableStructureClosure(array $tableInfo): callable
    {
        // Build the closure using given structure.
        return function (Blueprint $table) use ($tableInfo) {
            // Allow keys to be passed in with special... key.
            $keys = [];
            if (array_key_exists('keys', $tableInfo)) {
                $keys = $tableInfo['keys'];
                unset($tableInfo['keys']);
            }

            // One statement per column to be created.
            foreach ($tableInfo as $columnName => $type) {
                if (is_array($type)) {
                    // Handle enums first (blocking potential `strpos()` on an array).
                    $table->enum($columnName, $type)->nullable(); // $type == $options.
                } elseif (strpos($type, 'varchar') === 0) {
                    // Handle varchars.
                    $length = $this->getVarcharLength($type);
                    $table->string($columnName, $length)->nullable();
                } elseif (strpos($type, 'varbinary') === 0) {
                    // Handle varbinary as blobs.
                    $table->binary($columnName)->nullable();
                } else {
                    // Handle everything else.
                    // But first, un-abbreviate 'int' (e.g. `bigint`, `tinyint(1)`).
                    $type = preg_replace('/int($|\()/', 'integer', $type);
                    $table->$type($columnName)->nullable();
                }
            }

            // One statement per key to be created.
            foreach ($keys as $keyName => $info) {
                if ($info['type'] === 'unique') {
                    $table->unique($info['columns'], $keyName);
                } elseif ($info['type'] === 'index') {
                    $table->index($info['columns'], $keyName);
                } elseif ($info['type'] === 'primary') {
                    $table->primary($info['columns'][0]);
                }
                // @todo Allow more key types as needed.
            }
        };
    }

    /**
     * Disable foreign key & secondary unique checking temporarily for import.
     *
     * Does not disable primary unique key enforcement (which is not possible).
     * Required by interface.
     */
    public function begin(): void
    {
        $dbm = $this->connectionManager->dbm->getConnection($this->connectionManager->getAlias());
        $dbm->unprepared("SET foreign_key_checks = 0");
        $dbm->unprepared("SET unique_checks = 0");
    }

    /**
     * Re-enable foreign key & secondary unique checking.
     *
     * Does not enforce constraints on existing data.
     * Required by interface.
     */
    public function end(): void
    {
        $dbm = $this->connectionManager->dbm->getConnection($this->connectionManager->getAlias());
        $dbm->unprepared("SET foreign_key_checks = 1");
        $dbm->unprepared("SET unique_checks = 1");
    }

    /**
     * @param string $type
     * @return int
     */
    private function getVarcharLength(string $type): int
    {
        $matches = [];
        preg_match('/varchar\(([0-9]{1,3})\)/', $type, $matches);
        return (int)$matches[1] ?: 100;
    }
}
