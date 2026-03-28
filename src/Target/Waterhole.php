<?php

/**
 *
 * @author Lincoln Russell, lincolnwebs.com
 * @author Toby Zerner, tobyzerner.com
 */

namespace Porter\Target;

use Porter\Log;
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
     */
    public function validate(): void
    {
        $this->uniqueUserNames();
        $this->uniqueUserEmails();
    }

    /**
     * @return string[]
     */
    protected function getStructurePosts(): array
    {
        return self::DB_STRUCTURE_POSTS;
    }

    /**
     * Enforce unique usernames. Report users skipped (because of `insert ignore`).
     *
     * Unsure this could get automated fix. You'd have to determine which has/have data attached and possibly merge.
     * You'd also need more data from findDuplicates, especially the IDs.
     * Folks are just gonna need to manually edit their existing forum data for now to rectify dupe issues.
     */
    public function uniqueUserNames(): void
    {
        $allowlist = [
            '[Deleted User]',
            '[DeletedUser]',
            '-Deleted-User-',
            '[Slettet bruker]', // Norwegian
            '[Utilisateur supprimé]', // French
        ]; // @see fixDuplicateDeletedNames()
        $dupes = array_diff($this->findDuplicates('User', 'Name'), $allowlist);
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.name: ' . implode(', ', $dupes));
        }
    }

    /**
     * Enforce unique emails. Report users skipped (because of `insert ignore`).
     *
     * @see uniqueUserNames
     *
     */
    public function uniqueUserEmails(): void
    {
        $dupes = $this->findDuplicates('User', 'Email');
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.email: ' . implode(', ', $dupes));
        }
    }

    /**
     * Main import process.
     */
    public function run(): void
    {
        // Ignore constraints on tables that block import.
        $this->ignoreOutputDuplicates('users');

        $this->users();
        $this->roles(); // 'Groups' in Waterhole
        $this->categories(); // 'Channels' in Waterhole
        $this->discussions(); // 'Posts' in Waterhole
        $this->comments();
    }

    /**
     */
    protected function users(): void
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
        $query = $this->porterQB()->from('User')->select();

        $this->import('users', $query, $structure, $map, $filters);
    }

    /**
     * Waterhole handles role assignment in a magic way.
     *
     * This compensates by shifting all RoleIDs +4, rendering any old 'Member' or 'Guest' role useless & deprecated.
     *
     */
    protected function roles(): void
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
        if (!$this->hasOutputSchema('UserRole')) {
            Log::comment('Skipping import: Roles (Source lacks support)');
            $this->importEmpty('groups', $structure);
            $this->importEmpty('group_user', $structure);
            return;
        }

        // Delete orphaned user role associations (deleted users).
        $this->pruneOrphanedRecords('UserRole', 'UserID', 'User', 'UserID');

        $query = $this->porterQB()->from('Role')
            ->select()
            ->selectRaw('0 as is_public');

        $this->import('groups', $query, $structure, $map);

        // User Role.
        $structure = [
            'user_id' => 'bigint',
            'group_id' => 'bigint',
        ];
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $this->porterQB()->from('UserRole')
            ->select();

        $this->import('group_user', $query, $structure, $map);
    }

    /**
     */
    protected function categories(): void
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
        $query = $this->porterQB()->from('Category')
            ->select()
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.

        $this->import('channels', $query, $structure, $map);
    }

    /**
     */
    protected function discussions(): void
    {
        $structure = $this->getStructurePosts();
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
        $query = $this->porterQB()->from('Discussion')
            ->select()
            ->selectRaw('DiscussionID as slug');

        $this->import('posts', $query, $structure, $map, $filters);
    }

    /**
     */
    protected function comments(): void
    {
        $map = [
            'CommentID' => 'id',
            'DiscussionID' => 'post_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'edited_at',
            'Body' => 'body'
        ];
        $query = $this->porterQB()->from('Comment')
            ->select(['CommentID',
                'DiscussionID',
                'InsertUserID',
                'DateInserted',
                'DateUpdated',
                'Body',
                'Format']);

        $this->import('comments', $query, self::DB_STRUCTURE_COMMENTS, $map);
    }
}
