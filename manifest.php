<?php

/** List of all features that Nitro Porter packages can support. */

return [
    // Prepare
    'setup', // Pre-migration actions.
    'filemap', // Map a file transfer.

    // Users
    'users',
    'roles',
    'badges',
    'ranks',
    'signatures',
    'avatars',

    // Taxonomy
    'categories',
    'groups',
    'tags',
    'emojis',

    // Content
    'precontent', // Build references for content migration.
    'discussions',
    'comments',
    'conversations', // (private / direct messages)
    'wallposts', // (public profile posts)
    'usernotes', // (private profile posts)
    'attachments',
    'reactions',
    'bookmarks',
    'polls',

    // Finalize
    'filetransfer', // Do the file transfer.
    'cleanup', // Post-migration actions.
];
