<?php

/** List of all features that Nitro Porter packages can support. */

return [
    // Preparation steps
    'setup', // Pre-migration actions.
    'filemap', // Map a file transfer.

    // Users components
    'users',
    'roles',
    'badges',
    'ranks',
    'signatures',
    'avatars',

    // Taxonomy components
    'categories',
    'groups',
    'tags',
    'emojis',

    // Mid-migration step
    'precontent', // Build references for content migration.

    // Content components
    'discussions',
    'comments',
    'conversations', // (private / direct messages)
    'wallposts', // (public profile posts)
    'usernotes', // (private profile posts)
    'attachments',
    'reactions',
    'bookmarks',
    'polls',

    // Finalization steps
    'filetransfer', // Do the file transfer.
    'cleanup', // Post-migration actions.
];
