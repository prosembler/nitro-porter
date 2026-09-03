<?php

namespace Porter\Filter;

use Porter\Filter;

/** UserVoice role IDs are hex strings of hyphenated octets. Create an integer RoleID using the first 4 characters. */
class UserVoiceRoleID extends Filter
{
    public function __invoke(): mixed
    {
        return (int)hexdec(substr($this->value, 0, 4));
    }
}
