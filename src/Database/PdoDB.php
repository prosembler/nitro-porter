<?php

namespace Porter\Database;

use PDO;

/**
 * @deprecated
 */
class PdoDB
{
    private PDO $link;

    private \PDOStatement|false|null $result = null;

    public function __construct(PDO $pdo)
    {
        $this->link = $pdo;
    }

    public function query(string $sql): false|ResultSet
    {
        if (!empty($this->result)) {
            $this->result->closeCursor();
        }
        $this->result = $this->link->query($sql);

        if ($this->result === false) {
            print_r($this->link->errorInfo());
            return false;
        }
        return new ResultSet($this);
    }

    public function nextRow(): false|array
    {
        if (empty($this->result)) {
            return false;
        }
        $row = $this->result->fetch(\PDO::FETCH_ASSOC);
        if (isset($row)) {
            return $row;
        }
        $this->result->closeCursor();
        return false;
    }
}
