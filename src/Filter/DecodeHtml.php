<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * Decode the HTML out of a value.
 */
class DecodeHtml extends Filter
{
    public function __invoke(): mixed
    {
        // Uses default flags as of PHP 8.1.
        $encoding = defined('PORTER_INPUT_ENCODING') ? PORTER_INPUT_ENCODING : 'UTF-8';
        return html_entity_decode($this->value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, $encoding);
    }
}
