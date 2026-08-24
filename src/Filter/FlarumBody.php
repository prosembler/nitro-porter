<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * @see Migration::filterData()
 * @see \Porter\Target\Flarum::comments()
 */
class FlarumBody extends Filter
{
    public function __invoke(): mixed
    {
        $format = $this->row['Format'] ?? 'Text'; // Apparently null 'Format' values are possible.
        return \Porter\Formatter::instance()->toTextFormatter($format, $this->value);
    }
}
