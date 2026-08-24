<?php

namespace Porter\Filter;

use Porter\Filter;

class InvertInt extends Filter
{
    public function __invoke(): mixed
    {
        return (int)(!$this->value);
    }
}
