<?php

namespace Porter;

abstract class Target extends Package
{
    /** @var ConnectionManager  */
    public ConnectionManager $connection;

    public function __construct(protected ?Storage $porterStorage = null, protected ?Storage $outputStorage = null)
    {
    }

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
     * Enforce unique usernames. Report users skipped (because of `insert ignore`).
     *
     * Unsure this could get automated fix. You'd have to determine which has/have data attached and possibly merge.
     * You'd also need more data from findDuplicates, especially the IDs.
     * Folks are just gonna need to manually edit their existing forum data for now to rectify dupe issues.
     *
     * @param Migration $port
     */
    protected function uniqueUserNames(Migration $port): void
    {
        $allowlist = [
            '[Deleted User]',
            '[DeletedUser]',
            '-Deleted-User-',
            '[Slettet bruker]', // Norwegian
            '[Utilisateur supprimé]', // French
        ]; // @see fixDuplicateDeletedNames()
        $dupes = array_diff($this->findDuplicates('User', 'Name', $port), $allowlist);
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.name: ' . implode(', ', $dupes));
        }
    }

    /**
     * Enforce unique emails. Report users skipped (because of `insert ignore`).
     *
     * @param Migration $port
     * @see uniqueUserNames
     *
     */
    protected function uniqueUserEmails(Migration $port): void
    {
        $dupes = $this->findDuplicates('User', 'Email', $port);
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
