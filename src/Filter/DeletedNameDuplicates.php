<?php

namespace Porter\Filter;

use Porter\Filter;
use Porter\Formatter;

class DeletedNameDuplicates extends Filter
{
    public function __invoke(): mixed
    {
        return Formatter::instance()->deletedNameDuplicates($this->value, $this->row['UserID']);
    }
}
