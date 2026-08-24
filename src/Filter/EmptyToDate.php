<?php

namespace Porter\Filter;

use Porter\Filter;

class EmptyToDate extends Filter
{
    public function __invoke(): mixed
    {
        if (!$this->value || str_contains($this->value, '0000-00-00')) {
            return gmdate('Y-m-d H:i:s');
        }
        return $this->value;
    }
}
