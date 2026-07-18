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

    /** @var ConnectionManager  */
    public ConnectionManager $connection;

    public function __construct(public ?Storage $porterStorage = null, public ?Storage $outputStorage = null)
    {
    }

    /**
     * Provide the output database connection.
     */
    public function dbPorter(): Connection
    {
        return $this->porterStorage->getHandle();
    }

    /**
     * Provide the output database connection.
     */
    public function dbOutput(): Connection
    {
        return $this->outputStorage->getHandle();
    }

    /**
     * Provide a query builder for the porter database.
     */
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
     * Get the configured offset value.
     */
    protected function getOffset(string $name): int
    {
        $valid = ['users', 'roles', 'categories', 'discussions', 'comments',
            'attachments','polls', 'polloptions', 'tags', 'badges'];
        if (!in_array($name, $valid)) {
            Log::comment('Invalid offset name: ' . $name);
            return 0;
        }
        $offsets = Config::getInstance()->getOffsets();
        return (!empty($offsets[$name]) && is_numeric($offsets[$name])) ? (int) $offsets[$name] : 0;
    }

    /**
     * Creates $filters closures that add the offset values.
     */
    protected function setOffsets(array $map): array
    {
        $keys = array_keys($map);
        $filters = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, self::MERGE_KEYS)) {
                // Translate the mapped key to the offset key.
                $offset = $this->getOffset(self::MERGE_KEYS[$key]);
                // Create a single-use filter with exactly the correct offset addition.
                if ($offset) { // Don't set a filter if the offset=0.
                    $filters[$key] = function ($value) use ($offset) {
                        return $value + $offset;
                    };
                    Log::comment(sprintf('Offset %s is set to: %s', $key, $offset));
                }
            }
        }
        return $filters;
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
        $allowlist = [
            '[Deleted User]',
            '[DeletedUser]',
            '-Deleted-User-',
            '[Slettet bruker]', // Norwegian
            '[Utilisateur supprimé]', // French
        ]; // @see fixDuplicateDeletedNames()
        $dupes = array_diff($this->findDuplicates('User', 'Name'), $allowlist);
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.name: ' . implode(', ', $dupes));
        }
    }

    /**
     * Enforce unique emails. Report users skipped (because of `insert ignore`).
     * @see uniqueUserNames
     *
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
        $duplicates = $db->table($table)
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
        $folder = trim(static::SUPPORTED[$type . 'Path']  ?? '', '/');
        if ($addPath === 'full' && Config::getInstance()->get('target_root')) {
            $folder = rtrim(Config::getInstance()->get('target_root'), '/') . '/' . trim($folder, '/');
        } elseif ($addPath === 'web' && Config::getInstance()->get('target_webroot')) {
            $folder = rtrim(Config::getInstance()->get('target_webroot'), '/') . '/' . trim($folder, '/');
        }
        return $folder;
    }

    /**
     * Check if the output storage schema exists.
     */
    public function hasOutputSchema(string $table, array $columns = []): bool
    {
        return $this->outputStorage->exists($table, $columns);
    }

    /**
     * Ignore duplicates for a SQL storage target table. Adds prefix for you.
     */
    public function ignoreOutputDuplicates(string $tableName): void
    {
        if (method_exists($this->outputStorage, 'ignoreTable')) {
            $this->outputStorage->ignoreTable($tableName);
        }
    }

    /**
     * Check if the porter storage schema exists.
     */
    public function hasPortSchema(string $table, array $columns = []): bool
    {
        return $this->porterStorage->exists($table, $columns);
    }

    /**
     * Create empty import tables.
     */
    public function importEmpty(string $tableName, array $structure): void
    {
        $this->outputStorage->prepare($tableName, $structure);
    }

    /**
     * @param string $tableName
     * @param Builder $exp Connected to porterStorage.
     * @param array $struct
     * @param array $map
     * @param array $filters
     */
    public function import(string $tableName, Builder $exp, array $struct, array $map = [], array $filters = []): void
    {
        // Start timer.
        $start = microtime(true);

        // Automate merge offsets. (Keys must be in the $map or auto-offset will fail.)
        $filters = array_merge($filters, $this->setOffsets($map));

        // Prepare the storage medium for the incoming structure.
        $this->outputStorage->prepare($tableName, $struct);

        // Store the data.
        $info = $this->outputStorage->store($tableName, $map, $struct, $exp, $filters);

        // Report.
        Log::storage('import', $tableName, microtime(true) - $start, $info['rows'], $info['memory']);
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
            Log::storage('map', 'Media.TargetFullPath', microtime(true) - $start, $rows, memory_get_usage());
        }

        // Map avatars if self::SUPPORTED[avatarPath] exists.
        if (method_exists($this, 'mapAvatars') && $fileTarget = $this->getPath('avatar', 'full')) {
            // Start timer.
            $start = microtime(true);
            Log::comment("Mapping avatars...");

            // Query & update.
            $rows = $this->mapAvatars($fileTarget);

            // Report.
            Log::storage('map', 'User.TargetAvatarFullPath', microtime(true) - $start, $rows, memory_get_usage());
        }
    }
}
