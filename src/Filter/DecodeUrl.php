<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * Alias urldecode() to sidestep native function overloading.
 */
class DecodeUrl extends Filter
{
    public function __invoke(): mixed
    {
        return urldecode($this->value);
    }
}
