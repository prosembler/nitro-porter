<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * Convert empty values to zero. Useful for 'not null' columns with default=0.
 */
class EmptyToSpace extends Filter
{
    public function __invoke(): mixed
    {
        return empty($this->value) ? ' ' : $this->value;
    }
}
