<?php

namespace Porter;

abstract class Target extends Package
{
    public const SUPPORTED = [
        'name' => '',
        'defaultTablePrefix' => '',
        'passwordHashMethod' => '',
        'avatarsPrefix' => '',
        'avatarThumbPrefix' => '',
        'avatarPath' => '',
        'avatarThumbPath' => '',
        'attachmentPath' => '',
        'attachmentThumbPath' => '',
        'features' => [],
    ];

    /** @var array Settings that change Target behavior. */
    protected const FLAGS = [];

    /**
     * If this is 'false', skip moving first post content to `Discussions.Body`.
     *
     * Do not change this default in child Targets.
     * Use `'hasDiscussionBody' => false` in FLAGS to declare your Target can skip this step.
     *
     * @var bool
     * @see Target::getDiscussionBodyMode()
     * @see Target::skipDiscussionBody()
     */
    protected bool $useDiscussionBody = true;

    public ConnectionManager $connection;

    /**
     * Get support info of the target package.
     *
     * @return array
     * @see Target::setSources()
     */
    public static function getSupport(): array
    {
        return static::SUPPORTED;
    }

    /**
     * Get name of the target package.
     *
     * @return string
     */
    public static function getName(): string
    {
        return static::SUPPORTED['name'];
    }

    /**
     * Get default table prefix of the target package.
     *
     * @return string
     */
    public static function getPrefix(): string
    {
        return static::SUPPORTED['defaultTablePrefix'];
    }

    /**
     * Retrieve characteristics of the package.
     *
     * @param string $name
     * @return mixed|null
     */
    public static function getFlag(string $name)
    {
        return (isset(static::FLAGS[$name])) ? static::FLAGS[$name] : null;
    }

    /**
     * @return bool
     */
    public function getDiscussionBodyMode(): bool
    {
        return $this->useDiscussionBody;
    }

    /**
     * Set `useDiscussionBody` to false.
     *
     * @return void
     */
    public function skipDiscussionBody()
    {
        $this->useDiscussionBody = false;
    }

    /** Do the main process for imports, table by table. */
    abstract public function run(Migration $port): void;

    /** Enforce data constraints required by the target platform. */
    abstract public function validate(Migration $port): void;

    /**
     * Get current max value of a column on a table in output (target).
     *
     * Do not use porter (PORT_) tables because we may have added records elsewhere.
     *
     * @param string $name
     * @param string $table
     * @param Migration $ex
     * @return int
     */
    protected function getMaxValue(string $name, string $table, Migration $ex): int
    {
        $max = $ex->dbOutput()->table($table)
            ->selectRaw('max(`' . $name . '`) as id')
            ->limit(1)->get()->pluck('id');
        return $max[0] ?? 0;
    }

    /**
     * Find duplicate records on the given table + column.
     *
     * @param string $table
     * @param string $column
     * @param Migration $port
     * @return mixed[]
     */
    protected function findDuplicates(string $table, string $column, Migration $port): array
    {
        $results = [];
        $db = $port->dbPorter();
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
        string $fnColumn,
        Migration $port
    ): void {
        // `DELETE FROM $table WHERE $column NOT IN (SELECT $fnColumn FROM $fnTable)`
        $db = $port->dbPorter();
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
}
