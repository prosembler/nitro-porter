<?php

namespace Porter\Filter;

use Porter\Filter;

/** Requires setting a literal 'FilterStringValue' in the $map. */
class NotEmptyToStringValue extends Filter
{
    public function __invoke(): mixed
    {
        if (empty($this->value)) {
            return null;
        }
        return $this->row['FilterStringValue'];
    }
}
