<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * When value is null, return 'blank_email_{id}'.
 */
class BlankEmails extends Filter
{
    public function __invoke(): mixed
    {
        if (empty($this->value)) {
            $value = 'blank_email_' . $this->row['UserID'] . '@example.com';
        }
        return $value ?? $this->value;
    }
}
