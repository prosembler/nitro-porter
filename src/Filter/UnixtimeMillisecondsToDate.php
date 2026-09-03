<?php

namespace Porter\Filter;

use Porter\Filter;

class UnixtimeMillisecondsToDate extends Filter
{
    public function __invoke(): mixed
    {
        if (empty($this->value)) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', (int) $this->value / 1000);
    }
}
