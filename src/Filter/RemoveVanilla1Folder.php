<?php

namespace Porter\Filter;

use Porter\Filter;

class RemoveVanilla1Folder extends Filter
{
    public function __invoke(): mixed
    {
        if (($pos = strpos($this->value, '/uploads/')) !== false) {
            return substr($this->value, $pos + 9);
        }
        return $this->value;
    }
}
