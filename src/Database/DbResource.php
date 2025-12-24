<?php

namespace Porter\Database;

use PDO;

/**
 * @deprecated
 */
interface DbResource
{
    /**
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo);

    /**
     * @param string $sql
     * @return bool|ResultSet return instance of ResultSet on success false on failure
     */
    public function query(string $sql): bool|ResultSet;

    /**
     * @param string $sql query
     */
    public function error(string $sql): void;

    /**
     * @param bool $assoc
     * @return array|bool returns the next row if possible false if we've reached the end of the result set.
     */
    public function nextRow(bool $assoc): bool|array;
}
