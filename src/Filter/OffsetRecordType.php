<?php

namespace Porter\Filter;

use Porter\Filter;

/** Create an offset for the ambiguous RecordID key. Requires 'RecordType' set. */
class OffsetRecordType extends Filter
{
    public function __invoke(): mixed
    {
        if (!empty($this->row['RecordType']) && is_int($this->value)) {
            $offset = \Porter\Config::getInstance()->getOffset(strtolower($this->row['RecordType']) . 's');
            if (!empty($offset)) { // e.g. ['comments'] = 1000
                $this->value += $offset;
            }
        }
        return $this->value;
    }
}
