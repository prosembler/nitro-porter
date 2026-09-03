<?php

namespace Porter\Filter;

use Porter\Filter;

/** Alias mb_decode_numericentity() */
class DecodeNumericEntity extends Filter
{
    public function __invoke(): mixed
    {
        if (function_exists('mb_decode_numericentity')) {
            $convmap = [0x0, 0x2FFFF, 0, 0xFFFF];
            return mb_decode_numericentity($this->value, $convmap, 'UTF-8');
        } else {
            return $this->value;
        }
    }
}
