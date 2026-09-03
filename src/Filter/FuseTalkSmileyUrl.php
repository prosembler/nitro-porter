<?php

namespace Porter\Filter;

use Porter\Filter;

class FuseTalkSmileyUrl extends Filter
{
    public function __invoke(): mixed
    {
        static $smileySearch = '<img src="i/expressions/';
        static $smileyReplace;
        if ($smileyReplace === null) {
            $smileyReplace = '<img src=' . '/expressions/';
        }
        if (str_contains($this->value, $smileySearch)) {
            $this->value = str_replace($smileySearch, $smileyReplace, $this->value);
        }
        return $this->value;
    }
}
