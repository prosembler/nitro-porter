<?php

/**
 *
 * @author Lincoln Russell, lincolnwebs.com
 * @author Toby Zerner, tobyzerner.com
 */

namespace Porter\Target;

use Porter\Formatter;
use Porter\Log;
use Porter\Target;

class Waterhole extends Target
{
    public const array INFO = [
        'name' => 'Waterhole',
        'defaultTablePrefix' => '',
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => true,
    ];

    /**
     * Check for issues that will break the import.
     */
    public function validate(): void
    {
        $this->uniqueUserNames();
        $this->uniqueUserEmails();
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
        $dupes = array_diff($this->findDuplicates('User', 'Name'), Formatter::DELETED_USERNAMES);
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
     * Ignore constraints on tables that block import.
     */
    public function setup(): void
    {
        $this->ignoreOutputDuplicates('users');
    }

    protected function users(): void
    {
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
            'Name' => 'DeletedNameDuplicates',
            'Email' => 'BlankEmails',
        ];
        $query = $this->porterQB()->from('User')->select();
        $this->import('users', $query, $this->getSchema('users'), $map, $filters);
    }

    /**
     * Waterhole handles role assignment in a magic way.
     *
     * This compensates by shifting all RoleIDs +4, rendering any old 'Member' or 'Guest' role useless & deprecated.
     *
     */
    protected function roles(): void
    {
        // Verify support.
        if (!$this->hasOutputSchema('UserRole')) {
            Log::comment('Skipping import: Roles (Source lacks support)');
            $this->importEmpty('groups', $this->getSchema('groups'));
            $this->importEmpty('group_user', $this->getSchema('group_user'));
            return;
        }

        // Delete orphaned user role associations (deleted users).
        $this->pruneOrphanedRecords('UserRole', 'UserID', 'User', 'UserID');

        $map = [
            'RoleID' => 'id',
            'Name' => 'name',
        ];
        $query = $this->porterQB()->from('Role')
            ->select()
            ->selectRaw('0 as is_public');
        $this->import('groups', $query, $this->getSchema('groups'), $map);

        // User Role.
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $this->porterQB()->from('UserRole')->select();
        $this->import('group_user', $query, $this->getSchema('group_user'), $map);
    }

    protected function categories(): void
    {
        $map = [
            'CategoryID' => 'id',
            'Name' => 'name',
            'UrlCode' => 'slug',
            'Description' => 'description',
        ];
        $query = $this->porterQB()->from('Category')
            ->select()
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.
        $this->import('channels', $query, $this->getSchema('channels'), $map);
    }

    protected function discussions(): void
    {
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
            'slug' => 'FormatUrl',
        ];
        // CountComments needs to be double-mapped so it's included as an alias also.
        $query = $this->porterQB()->from('Discussion')
            ->select()
            ->selectRaw('DiscussionID as slug');
        $this->import('posts', $query, $this->getSchema('posts'), $map, $filters);
    }

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
        $this->import('comments', $query, $this->getSchema('comments'), $map);
    }
}
