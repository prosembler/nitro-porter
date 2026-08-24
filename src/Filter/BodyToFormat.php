<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * Guess the Format of the Body.
 */
class BodyToFormat extends Filter
{
    public function __invoke(): mixed
    {
        if (str_contains($this->value, '[')) {
            return 'BBCode';
        } elseif (str_contains($this->value, '<')) {
            return 'Html';
        }
        return 'BBCode';
    }
}
