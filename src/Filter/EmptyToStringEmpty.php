<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * Convert empty values to zero. Useful for 'not null' columns with default=0.
 */
class EmptyToStringEmpty extends Filter
{
    public function __invoke(): mixed
    {
        return empty($this->value) ? '[empty]' : $this->value;
    }
}
