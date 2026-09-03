<?php

namespace Porter\Filter;

use Porter\Filter;

class YafPassword extends Filter
{
    public function __invoke(): mixed
    {
        $passwordFormats = [0 => 'md5', 1 => 'sha1', 2 => 'sha256', 3 => 'sha384', 4 => 'sha512'];
        $salt = $this->row['PasswordSalt'];
        $method = $this->row['PasswordFormat'];
        if (isset($passwordFormats[$method])) {
            $method = $passwordFormats[(int)$method];
        } else {
            $method = 'sha1';
        }
        return $method . '$' . $salt . '$' . $this->value . '$';
    }
}
