<?php

namespace Porter\Filter;

use Porter\Filter;

class MapDrupalFormat extends Filter
{
    public function __invoke(): mixed
    {
        return match ($this->value) {
            'filtered_html', 'full_html' => 'Html',
            default => 'BBCode',
        };
    }
}
