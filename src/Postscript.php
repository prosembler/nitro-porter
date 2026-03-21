<?php

namespace Porter;

use Illuminate\Database\Connection;
use Staudenmeir\LaravelCte\Query\Builder;

/**
 * Custom data finalization post-migration for database targets.
 *
 * Extend this class per-Target as required. It will automatically run after the Target of the same name.
 * If using file-based or remote storage, handle this outside Porter instead.
 */
abstract class Postscript extends Package
{
    public function __construct(protected ?Storage $outputStorage = null, protected ?Storage $postscriptStorage = null)
    {
    }

    /**
     * Provide the output database connection.
     */
    public function dbOutput(): Connection
    {
        return $this->outputStorage->getHandle();
    }

    /**
     * Provide a query builder for the input database.
     */
    public function outputQB(): Builder
    {
        return new Builder($this->dbOutput());
    }

    /**
     * Provide the postscript database connection.
     */
    public function dbPostscript(): Connection
    {
        return $this->postscriptStorage->getHandle();
    }

    /**
     * Provide a query builder for the input database.
     */
    public function postQB(): Builder
    {
        return new Builder($this->dbPostscript());
    }

    /**
     * Postscripts may need to access Storage directly (read).
     */
    public function outputStorage(): Storage
    {
        return $this->outputStorage;
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
}
