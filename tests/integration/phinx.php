<?php

return
[
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'test',
        'test' => (new \Porter\PorterConnection())->getAllInfo(),
    ],
    'version_order' => 'creation'
];
