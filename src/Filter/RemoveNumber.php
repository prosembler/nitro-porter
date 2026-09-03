<?php

namespace Porter\Filter;

use Porter\Filter;

class RemoveNumber extends Filter
{
    public function __invoke(): mixed
    {
        return preg_replace('/(\d*)\//', '', $this->value);
    }
}
