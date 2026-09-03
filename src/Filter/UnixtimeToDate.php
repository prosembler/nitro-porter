<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * Convert a timestamp to MySQL date format.
 * @see MySQL FROM_UNIXTIME()
 */
class UnixtimeToDate extends Filter
{
    public function __invoke(): mixed
    {
        return (empty($this->value)) ? null : gmdate('Y-m-d H:i:s', $this->value);
    }
}
