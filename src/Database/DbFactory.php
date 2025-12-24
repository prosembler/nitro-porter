<?php

namespace Porter\Database;

use PDO;

/**
 * @deprecated
 */
class DbFactory
{
    /** @var PDO */
    private PDO $pdo;

    /**
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return PdoDB
     */
    public function getInstance(): PdoDB
    {
        $className = '\Porter\Database\\PdoDB';
        return new $className($this->pdo);
    }
}
