<?php
// Values here are overridden by CLI inputs.
return [
    // Package names (e.g. 'Xenforo').
    'source' => '',
    'target' => '',

    // Database table prefixes (leave blank for default).
    'source_prefix' => '',
    'target_prefix' => '',

    // Paths to local install folders (optional, for if files need renaming).
    // Even if it's not actually installed locally, just mirror its file structure for media files.
    // If the platform uses subfolders for thumbnails etc, the package should figure that out.
    'source_root' => '', // Example: '/source/folder'
    'target_root' => '', // Example: '/target/folder'

    // Relative web path to the new platform install (for links).
    // If your platform is installed in the root (e.g. https://example.com is 'home'), leave this blank.
    // If your platform is in a subfolder, note it here.
    //  (e.g. https://example.com/community would make this value 'community').
    'target_webroot' => '',

    // Aliases of connections.
    // (If you're just editing the 2 default connections below, don't change these.)
    'input_alias' => 'input',
    'output_alias' => 'output',

    // Data connections.
    'connections' => [
        [
            'alias' => 'input',
            'type' => 'database',
            'adapter' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'name' => 'porter',
            'user' => 'porter',
            'pass' => 'porter',
            'charset' => 'utf8mb4',
            'options' => [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // Critical for large datasets.
            ],
        ],
        [
            'alias' => 'output',
            'type' => 'database',
            'adapter' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'name' => 'porter',
            'user' => 'porter',
            'pass' => 'porter',
            'charset' => 'utf8mb4',
            'options' => [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // Critical for large datasets.
            ],
        ],
    ],

    // Advanced options.
    'option_cdn_prefix' => '',
    'option_data_types' => '',
    'debug' => false,
    'test_alias' => 'test',
];
