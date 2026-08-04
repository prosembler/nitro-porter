<?php

/**
 * Utility functions.
 */

use Porter\Log;

/**
 * Retrieve the config.
 */
function loadConfig(): array
{
    if (file_exists(ROOT_DIR . '/config.php')) {
        return require(ROOT_DIR . '/config.php');
    } else {
        return require(ROOT_DIR . '/config-sample.php');
    }
}

/**
 * Retrieve an array from named file in `/data`.
 */
function loadData(string $name): array
{
    $data = ['origins', 'sources', 'targets', 'structure'];
    if (in_array($name, $data, true)) {
        return include(ROOT_DIR . '/data/' . $name . '.php');
    } else {
        return [];
    }
}
