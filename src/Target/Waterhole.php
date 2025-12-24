<?php

/**
 *
 * @author Lincoln Russell, lincolnwebs.com
 * @author Toby Zerner, tobyzerner.com
 */

namespace Porter\Target;

use Porter\Log;
use Porter\Migration;
use Porter\Target;

class Waterhole extends Target
{
    public const SUPPORTED = [
        'name' => 'Waterhole',
        'defaultTablePrefix' => '',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 'channels',
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 0,
            'Attachments' => 0,
            'Bookmarks' => 0,
            'Badges' => 0,
            'UserNotes' => 0,
            'Ranks' => 0,
            'Groups' => 0,
            'Tags' => 0,
            'Reactions' => 0,
        ]
    ];

    protected const FLAGS = [
        'hasDiscussionBody' => true,
    ];

    /**
     * @var array Table structure for `comments`.
     */
    protected const DB_STRUCTURE_COMMENTS = [
        'id' => 'bigint',
        'post_id' => 'bigint',
        'parent_id' => 'bigint',
        'user_id' => 'bigint',
        'body' => 'mediumtext',
        'created_at' => 'timestamp',
        'edited_at' => 'timestamp',
        'reply_count' => 'int',
        'score' => 'int',
    ];

    /**
     * @var array Table structure for 'posts`.
     */
    protected const DB_STRUCTURE_POSTS = [
        'id' => 'bigint',
        'channel_id' => 'bigint',
        'user_id' => 'bigint',
        'title' => 'varchar(255)',
        'slug' => 'varchar(255)',
        'body' => 'mediumtext',
        'created_at' => 'timestamp',
        'edited_at' => 'timestamp',
        'last_activity_at' => 'timestamp',
        'comment_count' => 'int',
        'score' => 'int',
        'is_locked' => 'tinyint',
    ];

    /**
     * Check for issues that will break the import.
     *
     * @param Migration $port
     */
    public function validate(Migration $port): void
    {
        $this->uniqueUserNames($port);
        $this->uniqueUserEmails($port);
    }

    /**
     * @param Migration $port
     * @return string[]
     */
    protected function getStructurePosts(Migration $port): array
    {
        return self::DB_STRUCTURE_POSTS;
    }

    /**
     * Enforce unique usernames. Report users skipped (because of `insert ignore`).
     *
     * Unsure this could get automated fix. You'd have to determine which has/have data attached and possibly merge.
     * You'd also need more data from findDuplicates, especially the IDs.
     * Folks are just gonna need to manually edit their existing forum data for now to rectify dupe issues.
     *
     * @param Migration $port
     */
    public function uniqueUserNames(Migration $port): void
    {
        $allowlist = [
            '[Deleted User]',
            '[DeletedUser]',
            '-Deleted-User-',
            '[Slettet bruker]', // Norwegian
            '[Utilisateur supprimé]', // French
        ]; // @see fixDuplicateDeletedNames()
        $dupes = array_diff($this->findDuplicates('User', 'Name', $port), $allowlist);
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.name: ' . implode(', ', $dupes));
        }
    }

    /**
     * Enforce unique emails. Report users skipped (because of `insert ignore`).
     *
     * @param Migration $port
     * @see uniqueUserNames
     *
     */
    public function uniqueUserEmails(Migration $port): void
    {
        $dupes = $this->findDuplicates('User', 'Email', $port);
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.email: ' . implode(', ', $dupes));
        }
    }

    /**
     * Main import process.
     */
    public function run(Migration $port): void
    {
        // Ignore constraints on tables that block import.
        $port->ignoreOutputDuplicates('users');

        $this->users($port);
        $this->roles($port); // 'Groups' in Waterhole
        $this->categories($port); // 'Channels' in Waterhole
        $this->discussions($port); // 'Posts' in Waterhole
        $this->comments($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $structure = [
            'id' => 'bigint',
            'name' => 'varchar(255)',
            'email' => 'varchar(255)',
            'email_verified_at' => 'timestamp',
            'password' => 'varchar(255)',
            'avatar' => 'varchar(255)',
            'created_at' => 'timestamp',
            'last_seen_at' => 'timestamp',
        ];
        $map = [
            'UserID' => 'id',
            'Name' => 'name',
            'Email' => 'email',
            'Password' => 'password',
            'Photo' => 'avatar',
            'DateInserted' => 'created_at',
            'DateLastActive' => 'last_seen_at',
            'Confirmed' => 'email_verified_at',
        ];
        $filters = [
            'Name' => 'fixDuplicateDeletedNames',
            'Email' => 'fixNullEmails',
        ];
        $query = $port->targetQB()->from('User')->select();

        $port->import('users', $query, $structure, $map, $filters);
    }

    /**
     * Waterhole handles role assignment in a magic way.
     *
     * This compensates by shifting all RoleIDs +4, rendering any old 'Member' or 'Guest' role useless & deprecated.
     *
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $structure = [
            'id' => 'bigint',
            'name' => 'varchar(255)',
            'color' => 'varchar(255)',
            'icon' => 'varchar(255)',
            'is_public' => 'tinyint',
        ];
        $map = [
            'RoleID' => 'id',
            'Name' => 'name',
        ];

        // Verify support.
        if (!$port->hasOutputSchema('UserRole')) {
            Log::comment('Skipping import: Roles (Source lacks support)');
            $port->importEmpty('groups', $structure);
            $port->importEmpty('group_user', $structure);
            return;
        }

        // Delete orphaned user role associations (deleted users).
        $this->pruneOrphanedRecords('UserRole', 'UserID', 'User', 'UserID', $port);

        $query = $port->targetQB()->from('Role')
            ->select()
            ->selectRaw('0 as is_public');

        $port->import('groups', $query, $structure, $map);

        // User Role.
        $structure = [
            'user_id' => 'bigint',
            'group_id' => 'bigint',
        ];
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $port->targetQB()->from('UserRole')
            ->select();

        $port->import('group_user', $query, $structure, $map);
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $structure = [
            'id' => 'bigint',
            'name' => 'varchar(255)',
            'slug' => 'varchar(255)',
            'description' => 'text',
        ];
        $map = [
            'CategoryID' => 'id',
            'Name' => 'name',
            'UrlCode' => 'slug',
            'Description' => 'description',
        ];
        $query = $port->targetQB()->from('Category')
            ->select()
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.

        $port->import('channels', $query, $structure, $map);
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $structure = $this->getStructurePosts($port);
        $map = [
            'DiscussionID' => 'id',
            'CategoryID' => 'channel_id',
            'InsertUserID' => 'user_id',
            'Name' => 'title',
            'DateInserted' => 'created_at',
            'DateLastComment' => 'last_activity_at',
            'Closed' => 'is_locked',
            'Body' => 'body',
        ];
        $filters = [
            'slug' => 'createDiscussionSlugs',
        ];

        // CountComments needs to be double-mapped so it's included as an alias also.
        $query = $port->targetQB()->from('Discussion')
            ->select()
            ->selectRaw('DiscussionID as slug');

        $port->import('posts', $query, $structure, $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $map = [
            'CommentID' => 'id',
            'DiscussionID' => 'post_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'edited_at',
            'Body' => 'body'
        ];
        $query = $port->targetQB()->from('Comment')
            ->select(['CommentID',
                'DiscussionID',
                'InsertUserID',
                'DateInserted',
                'DateUpdated',
                'Body',
                'Format']);

        $port->import('comments', $query, self::DB_STRUCTURE_COMMENTS, $map);
    }
}
