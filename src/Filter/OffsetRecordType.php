<?php

namespace Porter\Filter;

use Porter\Filter;

/** Create an offset for the ambiguous RecordID key. Requires 'RecordType' set. */
class OffsetRecordType extends Filter
{
    public function __invoke(): mixed
    {
        if (!empty($this->row['RecordType']) && is_int($this->value)) {
            $offsets = \Porter\Config::getInstance()->getOffsets();
            $offsetName = strtolower($this->row['RecordType']) . 's';
            if (!empty($offsets[$offsetName])) { // e.g. ['comments'] = 1000
                $this->value += (int)$offsets[strtolower($this->row['RecordType'])];
            }
        }
        return $this->value;
    }
}
