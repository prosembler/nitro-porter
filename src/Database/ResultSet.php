<?php

namespace Porter\Database;

/**
 * @deprecated
 */
class ResultSet
{
    private PdoDB $db;

    public function __construct(PdoDB $dbResource)
    {
        $this->db = $dbResource;
    }

    public function nextResultRow(): false|array
    {
        return $this->db->nextRow();
    }
}
