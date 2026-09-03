<?php

namespace Porter\Filter;

use Porter\Filter;

class SnowflakeToTimestamp extends Filter
{
    /** @var int Milliseconds from Unix Epoch. */
    public const int DISCORD_EPOCH_DIFF = 1288834974657;

    public function __invoke(): mixed
    {
        if (empty($this->value)) {
            return null;
        }
        $timestamp = (($this->value >> 22) + self::DISCORD_EPOCH_DIFF) / 1000;
        return gmdate("Y-m-d H:i:s", (int)$timestamp); // FROM_UNIXTIME() equivalent.
    }
}
