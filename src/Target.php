<?php

namespace Porter;

use Illuminate\Database\Connection;
use Staudenmeir\LaravelCte\Query\Builder;

abstract class Target extends Package
{
    /** Map standard Porter schema keys to the config offsets they should use.  */
    public const array MERGE_KEYS = [
        // Users
        'InsertUserID' => 'users',
        'UpdateUserID' => 'users',
        'DeleteUserID' => 'users',
        'ForeignUserID' => 'users',
        'LastCommentUserID' => 'users',

        // Roles
        'RoleID' => 'roles',

        // Categories
        'CategoryID' => 'categories',
        'ParentCategoryID' => 'categories',

        // Discussions
        'DiscussionID' => 'discussions',
        'LastDiscussionID' => 'discussions',

        // Comments
        'CommentID' => 'comments',
        "parentCommentID" => "comments",
        'LastCommentID' => 'comments',
        'FirstCommentID' => 'comments',

        // Attachments
        'MediaID' => 'attachments',
        'ForeignID' => 'attachments',
        'ForeignTable' => 'attachments',

        // Etc.
        'PollID' => 'polls',
        'PollOptionID' => 'polloptions',
        'TagID' => 'tags',
        'BadgeID' => 'badges',
    ];

    /** @var StorageConnection  */
    public StorageConnection $connection;

    /** @var array Real found schema on the output connection. */
    public array $foundSchema = [];

    public function __construct(
        public ?Storage $porterStorage = null,
        public ?Storage $outputStorage = null,
        public string $packageName = '',
    ) {
        $this->schemas = Schema::load($packageName); // Porter's stored copy of known schemas.
    }

    /** Provide the output database connection. */
    public function dbPorter(): Connection
    {
        return $this->porterStorage->getHandle();
    }

    /** Provide the output database connection. */
    public function dbOutput(): Connection
    {
        return $this->outputStorage->getHandle();
    }

    /** Provide a query builder for the porter database. */
    public function porterQB(): Builder
    {
        return new Builder($this->dbPorter());
    }

    /** Enforce data constraints required by the target platform. */
    abstract public function validate(): void;

    /**
     * Get current max value of a column on a table in output (target).
     *
     * Do not use porter (PORT_) tables because we may have added records elsewhere.
     */
    protected function getMaxValue(string $name, string $table): int
    {
        $max = $this->dbOutput()->table($table)
            ->selectRaw('max(`' . $name . '`) as id')
            ->limit(1)->get()->pluck('id');
        return $max[0] ?? 0;
    }

    /**
     * Build array of UserID=>Email to evaluate user merges required.
     */
    protected function getUserMergeList(string $tableName, string $IdFieldName, string $EmailFieldName): array
    {
        static $list = null;
        if (empty($list)) {
            $list = $this->dbOutput()->table($tableName)
                ->select([$IdFieldName, $EmailFieldName])->get()->toArray();
            $list = array_column($list, $EmailFieldName, $IdFieldName);
        }
        return $list;
    }

    /**
     * Creates closures in $filters that add the offset values or merge users.
     */
    protected function addKeyFilters(string $tableName, array $map, array $filters): array
    {
        // Preempt 'RecordID' special case.
        if (array_key_exists('RecordID', $map)) {
            $filters['RecordID'] = \Porter\Filter\OffsetRecordType::class;
            unset($map['RecordID']);
        }

        // Remove non-key $map fields.
        $map = array_filter($map, fn ($key) => in_array($key, self::MERGE_KEYS), ARRAY_FILTER_USE_KEY);

        // Evaluate keys in $map for required filters.
        foreach ($map as $portName => $targetName) {
            // Don't set a filter if offset=0.
            if (!$offset = Config::getInstance()->getOffset(self::MERGE_KEYS[$portName])) {
                continue;
            }

            if ('users' === self::MERGE_KEYS[$portName]) {
                // Add a filter to offset userID or merge user accounts.
                $targetUsers = $this->getUserMergeList($tableName, $map['UserID'], $map['Email']);
                $filters[$portName] = function ($value, $name, $row) use ($offset, $targetUsers) {
                    if ($foundUser = array_search($row['Email'], $targetUsers)) {
                        return $foundUser; // Merge users.
                    }
                    return $value + $offset; // Add new user with an offset ID.
                };
            } else {
                // Add a filter that adds the offset per schema.
                $filters[$portName] = function ($value) use ($offset) {
                    return $value + $offset;
                };
                Log::comment(sprintf('Offset %s is set to: %s', $portName, $offset));
            }
        }
        return $filters;
    }

    /**
     * Automatically adds filters to avoid null values on non-nullable columns.
     */
    protected function addNullFilters(string $tableName, array $map, array $filters): array
    {
        $tableSchema = $this->findSchema($tableName);
        foreach ($tableSchema as $column) {
            if (!empty($column['auto_increment']) || !empty($column['default'])) {
                continue;
            }
            if (false === $column['nullable']) {
                // @todo If we reverse filter anchoring to be on output, undo this.
                $portColumnName = array_search($column['name'], $map);
                if (false === $portColumnName) {
                    $portColumnName = $column['name'];
                    $map[$column['name']] = $column['name']; // Kludge an empty static value into $map.
                    //Log::comment('Added missing map for ' . $column['name'] . ' to use filter.');
                }
                if (!empty($filters[$portColumnName])) {
                    continue; // Don't overwrite existing filter.
                }
                $filters[$portColumnName] = match (true) {
                        str_contains($column['name'], 'email') => \Porter\Filter\BlankEmails::class,
                        str_contains($column['type'], 'char'),
                        str_contains($column['type'], 'text') => \Porter\Filter\EmptyToStringEmpty::class,
                        str_contains($column['type'], 'date') => \Porter\Filter\EmptyToDate::class,
                        default => \Porter\Filter\EmptyToZero::class, // int, float, catch-all
                };
                /** @todo Make a proper debug-only log; useful when building a new package.
                if (\Porter\Config::getInstance()->debugEnabled()) {
                    Log::comment('Added filter ' .
                        str_replace('Porter\Filter\\', '', $filters[$portColumnName]) .
                        ' to ' . $portColumnName . ' => ' . $tableName . '.' . $column['name']);
                }*/
            }
        }
        return [$map, $filters];
    }

    /**
     * Find duplicate records on the given table + column.
     */
    protected function findDuplicates(string $table, string $column): array
    {
        $results = [];
        $db = $this->dbPorter();
        $duplicates = $db->table($table)
            ->select($column, $db->raw('count(' . $column . ') as found_count'))
            ->groupBy($column)
            ->having('found_count', '>', '1')
            ->get();
        foreach ($duplicates as $dupe) {
            $results[] = $dupe->$column;
        }
        return $results;
    }

    /**
     * Enforce unique usernames. Report users skipped (because of `insert ignore`).
     *
     * Unsure this could get automated fix. You'd have to determine which has/have data attached and possibly merge.
     * You'd also need more data from findDuplicates, especially the IDs.
     * Folks are just gonna need to manually edit their existing forum data for now to rectify dupe issues.
     */
    protected function uniqueUserNames(): void
    {
        $dupes = array_diff($this->findDuplicates('User', 'Name'), Formatter::DELETED_USERNAMES);
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.name: ' . implode(', ', $dupes));
        }
    }

    /**
     * Enforce unique emails. Report users skipped (because of `insert ignore`).
     * @see uniqueUserNames
     */
    protected function uniqueUserEmails(): void
    {
        $dupes = $this->findDuplicates('User', 'Email');
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.email: ' . implode(', ', $dupes));
        }
    }

    /**
     * Prune records where a foreign key doesn't exist for them.
     *
     * This happens in the Porter format / intermediary step.
     * It must be complete BEFORE records are inserted into the Target due to FK constraints.
     *
     * @param string $table Table to prune.
     * @param string $column Column (likely a key) to be compared to the foreign key for its existence.
     * @param string $fnTable Foreign table to check for corresponding key.
     * @param string $fnColumn Foreign key to select.
     */
    public function pruneOrphanedRecords(
        string $table,
        string $column,
        string $fnTable,
        string $fnColumn
    ): void {
        // `DELETE FROM $table WHERE $column NOT IN (SELECT $fnColumn FROM $fnTable)`
        $db = $this->dbPorter();
        $db->table($table)
            ->whereNotIn($column, $db->table($fnTable)->pluck($fnColumn))
            ->delete();
    }

    /**
     * Return the requested path (without a trailing slash).
     *
     * @param string $type
     * @param string $addPath 'none', 'full', or 'web'
     * @return string
     */
    public function getPath(string $type, string $addPath = 'none'): string
    {
        $folder = trim(static::INFO[$type . 'Path']  ?? '', '/');
        if ($addPath === 'full' && Config::getInstance()->get('target_root')) {
            $folder = rtrim(Config::getInstance()->get('target_root'), '/') . '/' . trim($folder, '/');
        } elseif ($addPath === 'web' && Config::getInstance()->get('target_webroot')) {
            $folder = rtrim(Config::getInstance()->get('target_webroot'), '/') . '/' . trim($folder, '/');
        }
        return $folder;
    }

    /** Check if the output storage schema exists. */
    public function hasOutputSchema(string $table, array $columns = []): bool
    {
        return $this->outputStorage->exists($table, $columns);
    }

    /** Ignore duplicates for a SQL storage target table. Adds prefix for you. */
    public function ignoreOutputDuplicates(string $tableName): void
    {
        if (method_exists($this->outputStorage, 'ignoreTable')) {
            $this->outputStorage->ignoreTable($tableName);
        }
    }

    /** Check if the porter storage schema exists. */
    public function hasPortSchema(string $table, array $columns = []): bool
    {
        return $this->porterStorage->exists($table, $columns);
    }

    /** Create empty import tables. */
    public function importEmpty(string $tableName): void
    {
        $struct = $this->getSchema($tableName);
        if (empty($struct)) {
            Log::comment(sprintf('Empty structure for table %s', $tableName));
        }
        $this->outputStorage->prepare($tableName, $struct);
    }

    /** Lazy-load the output schema as-needed. */
    private function findSchema(string $tableName): array
    {
        if (empty($this->foundSchema[$tableName])) {
            $this->foundSchema[$tableName] = $this->dbOutput()->getSchemaBuilder()->getColumns($tableName);
        }
        return $this->foundSchema[$tableName];
    }

    public function selectFrom(string $tableName, ?string $as = null): Builder
    {
        return $this->porterQB()->from($tableName, $as)->select();
    }

    /** Automate import transformations specified by a Component. */
    public function runComponent(Component $component): void
    {
        foreach ($component->transformations as $tf) {
            $data = (is_string($tf->data)) ? $this->selectFrom($tf->data) : $tf->data;
            $this->import($tf->outputSchemaName, $data, $tf->map, $tf->filters);
        }
    }

    /** Import data to the Target. */
    public function import(string $tableName, Builder $exp, array $map = [], array $filters = []): void
    {
        // Automate merge offsets. (Keys must be in the $map or auto-offset will fail.)
        $filters = $this->addKeyFilters($tableName, $map, $filters);

        // Automate defaults for non-nullable fields missing defaults.
        list($map, $filters) = $this->addNullFilters($tableName, $map, $filters);

        // Prepare the storage medium for the incoming structure.
        $schema = $this->getCombinedSchema($tableName);
        $this->outputStorage->prepare($tableName, $schema);

        // Store the data.
        $info = $this->outputStorage->store($tableName, $map, $schema, $exp, $filters);

        // Report.
        Log::storage('import', $info);
    }

    /**
     * Setup the destination values for FileTransfer.
     * Requires package implements mapAttachments() and/or mapAvatars().
     */
    protected function filemap(): void
    {
        // Abort if we lack support.
        if (!$this->getFileTransferSupport()) {
            return;
        }

        // Map attachments if self::SUPPORTED[attachmentPath] exists.
        if (method_exists($this, 'mapAttachments') && $fileTarget = $this->getPath('attachment', 'full')) {
            // Start timer.
            $start = microtime(true);
            Log::comment("Mapping attachments...");

            // Query & update.
            $rows = $this->mapAttachments($fileTarget);

            // Report.
            $info = new StorageInfo(
                name: 'Media.TargetFullPath',
                memory: memory_get_usage(),
                rows: $rows,
                startTime: $start,
            );
            Log::storage('map', $info);
        }

        // Map avatars if self::SUPPORTED[avatarPath] exists.
        if (method_exists($this, 'mapAvatars') && $fileTarget = $this->getPath('avatar', 'full')) {
            // Start timer.
            $start = microtime(true);
            Log::comment("Mapping avatars...");

            // Query & update.
            $rows = $this->mapAvatars($fileTarget);

            // Report.
            $info = new StorageInfo(
                name: 'User.TargetAvatarFullPath',
                memory: memory_get_usage(),
                rows: $rows,
                startTime: $start,
            );
            Log::storage('map', $info);
        }
    }

    /**
     * Combine Porter's stored simple schema (colName => colType) with any columns in the output connection.
     */
    protected function getCombinedSchema(string $tableName): array
    {
        $foundStruct = array_column($this->findSchema($tableName), 'type', 'name');
        return array_merge($this->getSchema($tableName), $foundStruct);
    }
}
