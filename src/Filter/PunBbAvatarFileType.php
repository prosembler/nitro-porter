<?php

namespace Porter\Filter;

use Porter\Filter;

/** Requires 'avatar' numeric field. */
class PunBbAvatarFileType extends Filter
{
    public function __invoke(): mixed
    {
        $extension = match ($this->row['avatar']) {
            1 => 'gif',
            2 => 'jpg',
            3 => 'png',
            default => null,
        };
        $avatarFilename = "{$this->value}.$extension";
        if (file_exists($avatarFilename)) {
            $avatarBasename = basename($avatarFilename);
            return "punbb/avatars/$avatarBasename";
        }
        return null;
    }
}
