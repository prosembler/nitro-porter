<?php

namespace Porter\Filter;

use Porter\Filter;
use Porter\Formatter;

class FormatUrl extends Filter
{
    public function __invoke(): mixed
    {
        return Formatter::instance()->toUrl($this->value);
    }
}
