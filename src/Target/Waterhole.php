<?php

/**
 *
 * @author Lincoln Russell, lincolnwebs.com
 * @author Toby Zerner, tobyzerner.com
 */

namespace Porter\Target;

use Porter\Component;
use Porter\Formatter;
use Porter\Log;
use Porter\Target;
use Porter\Transformation;

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
            Log::comment('! DATA LOSS: Users skipped for duplicate user.name: ' . implode(', ', $dupes));
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
            Log::comment('! DATA LOSS: Users skipped for duplicate user.email: ' . implode(', ', $dupes));
        }
    }

    /**
     * Ignore constraints on tables that block import.
     */
    public function setup(): void
    {
        $this->ignoreOutputDuplicates('users');
        // Delete orphaned user role associations (deleted users).
        $this->pruneOrphanedRecords('UserRole', 'UserID', 'User', 'UserID');
    }

    protected function users(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'users',
                data: 'User',
                map: [
                    'UserID' => 'id',
                    'Name' => 'name',
                    'Email' => 'email',
                    'Password' => 'password',
                    'Photo' => 'avatar',
                    'DateInserted' => 'created_at',
                    'DateLastActive' => 'last_seen_at',
                    'Confirmed' => 'email_verified_at',
                ],
                filters: [
                    'Name' => 'DeletedNameDuplicates',
                    'Email' => 'BlankEmails',
                ],
            ),
        ]);
    }

    /** 'Groups' in Waterhole. */
    protected function roles(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'groups',
                data: 'Role',
                map: [
                    'RoleID' => 'id',
                    'Name' => 'name',
                    'is_public=0',
                ],
            ),
            new Transformation(
                outputSchemaName: 'group_user',
                data: 'UserRole',
                map: [
                    'UserID' => 'user_id',
                    'RoleID' => 'group_id',
                ],
            ),
        ]);
    }

    /** 'Channels' in Waterhole. */
    protected function categories(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'channels',
                data: $this->porterQB()->from('Category')->select()
                    ->where('CategoryID', '!=', -1), // Ignore Vanilla's root category.
                map: [
                    'CategoryID' => 'id',
                    'Name' => 'name',
                    'UrlCode' => 'slug',
                    'Description' => 'description',
                ],
            ),
        ]);
    }

    /** 'Posts' in Waterhole. */
    protected function discussions(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'posts',
                data: 'Discussion',
                map: [
                    'DiscussionID' => ['id', 'slug'],
                    'CategoryID' => 'channel_id',
                    'InsertUserID' => 'user_id',
                    'Name' => 'title',
                    'DateInserted' => 'created_at',
                    'DateLastComment' => 'last_activity_at',
                    'Closed' => 'is_locked',
                    'Body' => 'body',
                ],
            ),
        ]);
    }

    protected function comments(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'comments',
                data: 'Comment',
                map: [
                    'CommentID' => 'id',
                    'DiscussionID' => 'post_id',
                    'InsertUserID' => 'user_id',
                    'DateInserted' => 'created_at',
                    'DateUpdated' => 'edited_at',
                    'Body' => 'body'
                ],
            ),
        ]);
    }
}
