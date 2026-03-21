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

    /** @var array|string[] List of PORT_ tables with primary keys. */
    public const array FK_TABLES = [
        'Activity',
        'ActivityComment',
        'ActivityType',
        'Attachment',
        'Badge',
        'Category',
        'Comment',
        'Conversation',
        'ConversationMessage', // Non-standard key name: 'MessageID'
        'Discussion',
        'Event',
        'Group',
        'GroupApplicant',
        'Media',
        'Poll',
        'PollOption',
        'Rank',
        'Role',
        'Status',
        'Tag',
        'User'
    ];

    /** @var array|string[] List of words that precede foreign key names (e.g. "InsertUserID") in Porter schema. */
    public const array FK_ACTIONS = [
        'Insert',
        'Update',
        'Delete',
        'Invite',
        'First',
        'Last',
        'parent',
    ];

    /**
     * Activity.RecordID
     * Attachment.ForeignID
     * Attachment.SourceID
     * Comment.parentRecordID
     * Conversation.ForeignID
     * Discussion.ForeignID
     * Discussion.RegardingID
     * Event.ParentRecordID
     * Media.ForeignID
     * UserNote.RecordID
     * UserTag.RecordID
     */
    public const array VARIABLE_KEYS = [
        'ForeignID',
        'RecordID',
        'SourceID',
        'RegardingID',
    ];

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

    /**
     * Every primary key in Porter schema is named predictably except one. :-/
     */
    public function getPK(string $table): string
    {
        if ('ConversationMessage' === $table) {
            return 'MessageID';
        }
        return $table . 'ID';
    }

    /**
     * Get associated Porter schema table being referenced by a foreign key.
     */
    public function mapFK(string $foreignKey): string
    {
        // All FKs end in 'ID' — bail if not found, or remove it.
        if (!str_ends_with($foreignKey, 'ID')) {
            return '';
        }
        $base = substr($foreignKey, 0, -2);

        // Handle then ConversationMessage edge case (assumes 'Message' appears in no other column).
        $base = str_replace('Message', 'ConversationMessage', $base);

        // $base is perhaps now one of:
        //  - Simple {table}ID names: `UserID`, `DiscussionID`, `CommentID`
        //  - Compound {action}{table}ID names: `InsertUserID`, `InviteUserID`, `parentCategoryID`
        // so if we simply strip action words, we cover both cases
        $base = str_replace(self::FK_ACTIONS, '', $base);

        // ...but let's be sure it's valid because that logic is highly dependent on Porter's current schema.
        if (in_array($base, self::FK_TABLES)) {
            return $base;
        }

        Log::comment("Found invalid base table '$base' from foreign key '$foreignKey'");
        return '';
    }

    /**
     * Create & join index renumbering maps (from '1' or using the offsets provided).
     */
    public function renumber(string $tableName, Builder $query, array $map, array $filters, array $offsets): array
    {
        // PRIMARY KEYS
        $portKey = $this->getPK($tableName);
        if ($sourceKey = array_search($portKey, $map, true)) {
            $start = microtime(true);

            // Create PORT_ZNUM_{table} with `id` (indexed for joining) & `tableID` (auto-increment).
            $keys = ['znum_index_' . $tableName . '_' . $sourceKey => [
                'type' => 'index',
                'columns' => [$sourceKey],
            ]];
            $structure = [$sourceKey => 'varchar(200)', $portKey => 'increments', 'keys' => $keys];
            $this->porterStorage->prepare('ZNUM_' . $tableName, $structure);

            // @todo Resepct the offsets

            // Fill the translation table
            $qb = $this->sourceQB()->from($query->from)->select($sourceKey)->orderBy($sourceKey);
            $info = $this->porterStorage->store($tableName, [], $structure, $qb, []);
            Log::storage('renumber', $tableName, microtime(true) - $start, $info['rows'], $info['memory']);

            // Join src ON srcID = portID to overwrite the primary key / map on the fly
            // If your ORIGIN lacks an index on its primary key, it will be painfully long to execute.
            $table1 = 'PORT_ZNUM_' . $tableName . '.' . $sourceKey;
            $table2 = $query->from . '.' . $sourceKey;
            $query->leftJoin('PORT_ZNUM_' . $tableName, $table1, '=', $table2)
                ->addSelect($portKey);
                //->selectRaw('PORT_ZNUM_' . $tableName . '.id' . ' as ' . $portKey);

            // Drop the renumbered key from the map (or the addSelect is overwritten)
            unset($map[$sourceKey]);
        }

        // FOREIGN KEYS
        // 1. Join consistent FKs.
        foreach ($map as $sourceName => $portName) {
            if ($portName === $portKey) {
                continue; // It's a primary key not a foreign one.
            }
            if ($baseTable = $this->mapFK($portName)) {
                //Log::comment("Found fk table '$baseTable' from '$portName'");
                $table1 = 'PORT_ZNUM_' . $baseTable . '.id';
                $table2 = $query->from . '.' . $sourceName;
                $query->leftJoin('PORT_ZNUM_' . $baseTable, $table1, '=', $table2)
                    ->selectRaw('PORT_ZNUM_' . $baseTable . '.' . $baseTable . 'ID as ' . $portName);
                // Drop the renumbered key from the map (or the addSelect is overwritten)
                unset($map[$sourceName]);
            }
        }

        // 2. Join self::VARIABLE_KEYS by determining which PK we want
        // @todo dynamically build IFs in SQL or add $filters.

        return [$query, $map];
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

        // Validate table structure exists.
        if (!array_key_exists($tableName, $this->porterStructure)) {
            Log::comment("Error: $tableName is not a valid table for export.");
            return;
        }
        $structure = $this->porterStructure[$tableName];

        // Pre-run the export query if it's raw SQL from a legacy Source.
        if (is_string($query)) { // @todo remove this support after Sources are updated
            $data = $this->query($query);
            if (empty($data)) {
                Log::comment("Error: No data found in $tableName.");
                return;
            }
            // NOT possible to renumber automatically for a legacy Source.
            if ($this->getFlag('renumberIndices') === true) {
                Log::comment("Notice: Cannot renumber index in $tableName.");
            }
            if (Config::getInstance()->mergeEnabled()) {
                Log::comment("Notice: Cannot prepare merge for $tableName.");
            }
        } elseif ($this->getFlag('renumberIndices') === true || Config::getInstance()->mergeEnabled()) {
            // Non-legacy Sources can get renumbered automatically if enabled.
            $offsets = Config::getInstance()->getOffsets();
            list($query, $map) = $this->renumber($tableName, $query, $map, $filters, $offsets);
        }

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
