<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * Derive mimetype from file extension (or filename).
 */
class ExtToMime extends Filter
{
    public function __invoke(): mixed
    {
        $value = $this->value;
        if (str_contains($value, '.')) {
            $value = pathinfo($value, PATHINFO_EXTENSION);
        }
        return match ($value) {
            'png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp' => 'image/' . $value,
            'zip', 'doc', 'docx', 'pdf', 'xls', 'swf' => 'application/' . $value,
            'txt', 'htm', 'html' => 'text/' . $value,
            'mov', 'avi' => 'video/' . $value,
            default => '',
        };
    }
}
