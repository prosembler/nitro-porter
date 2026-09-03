<?php

namespace Porter\Filter;

use Porter\Filter;

class ExtractColonDelimNumber extends Filter
{
    public function __invoke(): mixed
    {
        preg_match('/\w*:(\d*):/', $this->value, $matches);
        return $matches[1] ?? '';
    }
}
