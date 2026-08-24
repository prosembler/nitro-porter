<?php

namespace Porter\Filter;

use Porter\Filter;

class AngleToSquareBrackets extends Filter
{
    public function __invoke(): mixed
    {
        if (strpos($this->value, '[') !== false) {
            return str_replace(['<', '>'], ['[', ']'], $this->value);
        }
        return $this->value;
    }
}
