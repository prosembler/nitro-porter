<?php

namespace Porter\Filter;

use Porter\Filter;
use Porter\Formatter;

class FormatToHtml extends Filter
{
    public function __invoke(): mixed
    {
        $val = Formatter::instance()->toHtml($this->row['Format'] ?? 'Text', $this->value);
        return empty($val) ?  ' ' : $val; // Empty string would convert to null in Schema::normalizeRow().
    }
}
