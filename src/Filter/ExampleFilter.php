<?php

namespace Porter\Filter;

use Porter\Filter;

class ExampleFilter extends Filter
{
    public function __invoke(): mixed
    {
        // $this->row; // [columnName => value] of entire record
        // $this->columnName; // of the current value.
        return $this->value; // Simply returning the existing value gives the filter no effect.
    }
}
