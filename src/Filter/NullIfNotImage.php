<?php

namespace Porter\Filter;

use Porter\Filter;

/** Requires selecting either filename/path as 'Path', extension as 'Ext', or mimetype as 'Mime'. */
class NullIfNotImage extends Filter
{
    public function __invoke(): mixed
    {
        if (!empty($this->row['Path']) && empty($this->row['Ext'])) {
            // Set Ext for the next condition to catch.
            $this->row['Ext'] = pathinfo($this->row['Path'], PATHINFO_EXTENSION);
        }
        if (!empty($this->row['Ext']) && in_array($this->row['Ext'], ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'])) {
            return $this->value;
        }
        if (!empty($this->row['Mime']) && str_starts_with($this->row['Mime'], 'image/')) {
            return $this->value;
        }
        return null;
    }
}
