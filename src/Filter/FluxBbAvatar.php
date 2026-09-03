<?php

namespace Porter\Filter;

use Porter\Filter;

/**
 * Take the user ID, avatar type value and generate a path to the avatar file.
 * Requires 'id' (UserID).
 */
class FluxBbAvatar extends Filter
{
    public function __invoke(): mixed
    {
        $extension = match ($this->value) {
            1 => 'gif',
            2 => 'jpg',
            3 => 'png',
            default => null
        };
        $avatarFilename = "/{$this->row['id']}.$extension";
        if (file_exists($avatarFilename)) {
            $avatarBasename = basename($avatarFilename);
            return "fluxbb/img/avatars/$avatarBasename";
        } else {
            return null;
        }
    }
}
