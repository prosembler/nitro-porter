<?php

namespace Porter\Filter;

use Porter\Filter;

class ExtractColonDelimWord extends Filter
{
    public function __invoke(): mixed
    {
        preg_match('/\w*:([\w\s-]*):/', $this->value, $matches);
        return $matches[1] ?? '';
    }
}
