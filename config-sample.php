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
    'origin_alias' => 'discord',
    'input_alias' => 'input',
    'output_alias' => 'output',
    // The `PORT_` intermediary is always relational; keep this on a MySQL/MariaDB connection.
    // For a document target (e.g. NodeBB on `mongodb`), set `output_alias` to the document store
    // and point `porter_alias` at a MySQL/MariaDB connection. Defaults to `output_alias`.
    'porter_alias' => 'output',

    // Data connections.
    'connections' => [
        [
            'alias' => 'input',
            'type' => 'database',
            // @see https://laravel.com/docs/12.x/database#read-and-write-connections
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'porter',
            'username' => 'porter',
            'password' => 'porter',
            'charset' => 'utf8mb4',
            'options' => [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // Critical for large datasets. Remove for non-MySQL.
            ],
        ],
        [
            'alias' => 'output',
            'type' => 'database',
            // @see https://laravel.com/docs/12.x/database#read-and-write-connections
            'driver' => 'mysql', // 'postgresql' for Discourse
            'host' => 'localhost',
            'port' => '3306', // '5432' for PostgresQL (usually)
            'database' => 'porter',
            'username' => 'porter',
            'password' => 'porter',
            'charset' => 'utf8mb4',
            'options' => [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // Critical for large datasets. REMOVE for non-MySQL.
            ],
        ],
        [
            'alias' => 'discord',
            'type' => 'api',
            // @see https://github.com/symfony/symfony/blob/8.0/src/Symfony/Contracts/HttpClient/HttpClientInterface.php
            // https://symfony.com/doc/current/reference/configuration/framework.html#reference-http-client-base-uri
            'base_uri' => 'https://discord.com/api/v10/', // Trailing slash required.
            'token' => 'secret.token',
            'extra' => [
                'guild_id' => '123', // Server ID
                //'channels' => ['123', '456'], // Optionally limit to certain Channel IDs
            ],
        ],
        [
            // Document store for a NodeBB target. Use as `output_alias` & keep `porter_alias` on MariaDB.
            'alias' => 'nodebb',
            'type' => 'mongodb',
            'host' => 'porter-mongo',
            'port' => '27017',
            'database' => 'nodebb',
            'username' => '',
            'password' => '',
        ],
    ],

    // Advanced options.
    'option_cdn_prefix' => '',
    'option_data_types' => '',
    'debug' => false,
    'test_alias' => 'test',
];
