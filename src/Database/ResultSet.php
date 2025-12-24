<?php

namespace Porter\Database;

/**
 * @deprecated
 */
class ResultSet
{
    /** @var DbResource */
    private DbResource $dbResource;

    /**
     * @param DbResource $dbResource
     */
    public function __construct(DbResource $dbResource)
    {
        $this->dbResource = $dbResource;
    }

    /**
     * Iterate to new result row via dbResource.
     *
     * @param bool $assoc will return result row as an enumerated array if false.
     * @return array|bool
     */
    public function nextResultRow(bool $assoc = true): bool|array
    {
        return $this->dbResource->nextRow($assoc);
    }
}
