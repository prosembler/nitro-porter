<?php

namespace Porter;

class Data
{
    /**
     * Retrieve an array from named file in `/data`.
     */
    public static function load(string $name): array
    {
        $data = ['structure'];
        if (in_array($name, $data, true)) {
            return include(ROOT_DIR . '/data/' . $name . '.php');
        } else {
            return [];
        }
    }
}
