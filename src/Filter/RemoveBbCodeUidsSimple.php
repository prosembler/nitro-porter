<?php

namespace Porter\Filter;

use Porter\Filter;

/** Requires field bbcode_uid */
class RemoveBbCodeUidsSimple extends Filter
{
    public function __invoke(): mixed
    {
        if (empty($this->row['bbcode_uid'])) {
            return $this->value;
        }
        return str_replace(':' . $this->row['bbcode_uid'], '', $this->value);
    }
}
