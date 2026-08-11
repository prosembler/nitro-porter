<?php

namespace Porter\Database;

use PDO;

/**
 * @deprecated
 */
class PdoDB implements DbResource
{
    private PDO $link;

    /** @var \PDOStatement|false|null query result */
    private \PDOStatement|false|null $result = null;

    /**
     * {@inheritdoc}
     */
    public function __construct(PDO $pdo)
    {
        // Mind if I cut in? Bridge to removing this entirely.
        $this->link = $pdo;
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $sql): false|ResultSet
    {
        if (!empty($this->result)) {
            $this->result->closeCursor();
        }
        $this->result = $this->link->query($sql);

        if ($this->result === false) {
            $this->error($sql);
            return false;
        }
        return new ResultSet($this);
    }

    /**
     * {@inheritdoc}
     */
    public function error(string $sql): void
    {
        print_r($this->link->errorInfo());
    }

    /**
     * {@inheritdoc}
     */
    public function nextRow(bool $assoc): false|array
    {
        if (empty($this->result)) {
            return false;
        }
        $row = $this->result->fetch($assoc ? \PDO::FETCH_ASSOC : \PDO::FETCH_NUM);
        if (isset($row)) {
            return $row;
        }

        $this->result->closeCursor();
        return false;
    }
}
