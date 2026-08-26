<?php

namespace Porter\Database;

use PDO;

/**
 * @deprecated
 */
class DbFactory
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getInstance(): PdoDB
    {
        return new \Porter\Database\PdoDB($this->pdo);
    }
}
