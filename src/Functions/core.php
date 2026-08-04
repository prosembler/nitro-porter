<?php

/**
 * Utility functions.
 */

use Porter\Log;



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
