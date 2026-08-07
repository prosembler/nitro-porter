<?php
// IMPORTANT: Values here are overridden by CLI inputs.
// Example: If you `run --source Flarum`, the 'source' below won't matter — your command takes precedence!
return [
    // Package names (e.g. 'Xenforo').
    'source' => '',
    'target' => '',

    // Relational database table prefixes (leave blank for package default; not used for non-relational storage).
    'source_prefix' => '',
    'target_prefix' => '',

    // Paths to local install folders (optional, for files that need renaming).
    // If it's not installed locally, you can still mock its file structure for media file storage.
    // If the platform uses subfolders for thumbnails etc, the package should figure that out.
    'source_root' => '', // Example: '/source/folder'
    'target_root' => '', // Example: '/target/folder'

    // Relative web path to the new platform install (for links).
    // If your platform is installed in the root (e.g. https://example.com is the homepage), leave this blank.
    // If your platform is in a subfolder, note it here.
    //  (e.g. https://example.com/community would make this value 'community').
    'target_webroot' => '',

    // Aliases of connections — safe defaults!
    'origin_alias' => 'discord', // Only used by 'pull' command, which writes to 'input_alias'.
    'input_alias' => 'input', // Where the Source package reads.
    'output_alias' => 'output', // For a document Target (e.g. NodeBB on `mongodb`), set this to the document store.
    'porter_alias' => 'output', // MUST be MySQL/MariaDB connection. Defaults to `output_alias` if empty.

    // Data connections.
    // @see https://laravel.com/docs/12.x/database#read-and-write-connections
    // @see https://github.com/symfony/symfony/blob/8.0/src/Symfony/Contracts/HttpClient/HttpClientInterface.php
    'connections' => [
        [
            'alias' => 'input',
            'type' => 'database',
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'porter',
            'username' => 'porter',
            'password' => 'porter',
            'charset' => 'utf8mb4',
            'options' => [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ],
        ],
        [
            'alias' => 'output',
            'type' => 'database',
            'driver' => 'mysql', // 'postgresql' for Discourse
            'host' => 'localhost',
            'port' => '3306', // '5432' for PostgresQL (usually)
            'database' => 'porter',
            'username' => 'porter',
            'password' => 'porter',
            'charset' => 'utf8mb4',
            'options' => [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // MySQL/MariaDB-only option critical for large datasets.
            ],
        ],
        [
            // Example API connection for pulling data to a local database.
            'alias' => 'discord',
            'type' => 'https',
            # @see https://symfony.com/doc/current/reference/configuration/framework.html#reference-http-client-base-uri
            'base_uri' => 'https://discord.com/api/v10/', // Trailing slash required.
            'token' => 'secret.token',
            'extra' => [
                'guild_id' => '123', // Server ID
                //'channels' => ['123', '456'], // Optionally limit to certain Channel IDs
            ],
        ],
        [
            // Example MongoDB document store for a NodeBB target.
            'alias' => 'nodebb', // Use this as `output_alias`value & keep `porter_alias` on MySQL/MariaDB.
            'type' => 'mongo',
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
