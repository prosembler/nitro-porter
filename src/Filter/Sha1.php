<?php

namespace Porter\Filter;

use Porter\Filter;

class Sha1 extends Filter
{
    public function __invoke(): mixed
    {
        return sha1($this->value);
    }
}
