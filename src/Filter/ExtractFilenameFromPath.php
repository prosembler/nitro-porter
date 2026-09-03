<?php

namespace Porter\Filter;

use Porter\Filter;

class ExtractFilenameFromPath extends Filter
{
    public function __invoke(): mixed
    {
        return pathinfo($this->value, PATHINFO_FILENAME);
    }
}
