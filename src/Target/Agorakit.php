<?php

/**
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Target;

use Porter\Migration;
use Porter\Target;

/**
 *
 */
class Agorakit extends Target
{
    public const SUPPORTED = [
        'name' => 'Agorakit',
        'defaultTablePrefix' => '',
        //'avatarPath' => '',
        //'attachmentPath' => '',
        'features' => [
            // round 0 - base
            'Users' => 1,
            'Passwords' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            // round 1 - mash
            'Categories' => 0, // @todo TAGS
            'Tags' => 0, // @todo ALSO TAGS
            'Roles' => 0, // @todo GROUPS
            'Groups' => 0, // @todo ALSO GROUPS
            // round 2 - kludge
            'Avatars' => 0, // @todo FILES
            'Attachments' => 0, // @todo ALSO FILES
            'Reactions' => 0, // @todo REACTIONS ???
            // round 3 - create
            'Bookmarks' => 0, // @todo no support
            'Polls' => 0, // @todo no support
            'Badges' => 0, // @todo no support
            // round 4 - rethink
            'PrivateMessages' => 0, // @todo no support
        ]
    ];

    protected const FLAGS = [
        'hasDiscussionBody' => true,
    ];

    /** @var int Offset for inserting OP content into the posts table. */
    protected int $discussionPostOffset = 0;

    /**
     * Main import process.
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->categories($port);

        $this->discussions($port);
        $this->comments($port);
    }

    /**
     * Check for issues that will break the import.
     *
     * @param Migration $port
     */
    public function validate(Migration $port): void
    {
        //
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $structure = [
            'id' => 'int',
            'name' => 'varchar(100)',
            'username' => 'varchar(100)',
            'email' => 'varchar(100)',
            'verified' => 'tinyint',
            'password' => 'varchar(100)',
            'created_at' => 'datetime',
            'admin' => 'tinyint',
        ];
        $map = [
            'UserID' => 'id',
            'Name' => 'username',
            'FullName' => 'name',
            'Email' => 'email',
            'Password' => 'password',
            'Confirmed' => 'verified',//
            //'Photo' => 'avatar_url',
            'DateInserted' => 'created_at',
            'Admin' => 'admin',
        ];
        $filters = [];
        $query = $port->targetQB()
            ->from('User')
            ->select();

        $port->import('users', $query, $structure, $map, $filters);
    }

    /**
     *
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $structure = [
            'id' => 'int',
            'name_singular' => 'varchar(100)',
        ];
        $map = [];
        $query = $port->targetQB()
            ->from('Role')
            ->select();

        $port->import('groups', $query, $structure, $map);

        // User Role.
        $structure = [
            'user_id' => 'int',
            'group_id' => 'int',
        ];
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $port->targetQB()
            ->from('UserRole')
            ->select();

        $port->import('group_user', $query, $structure, $map);
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $structure = [
            'id' => 'int',
            'name' => 'varchar(100)',
            'slug' => 'varchar(100)',
            'description' => 'text',
            'parent_id' => 'int',
            'position' => 'int',
            'discussion_count' => 'int',
            'is_hidden' => 'tinyint',
            'is_restricted' => 'tinyint',
        ];
        $map = [
            'CategoryID' => 'id',
            'Name' => 'name',
            'Description' => 'description',
            'ParentCategoryID' => 'parent_id',
            'Sort' => 'position',
            'CountDiscussions' => 'discussion_count',
        ];
        $filters = [
            'CountDiscussions' => 'emptyToZero',
        ];
        $query = $port->targetQB()
            ->from('Category')
            ->select()
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.

        $port->import('tags', $query, $structure, $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $structure = [];
        $map = [
            'DiscussionID' => 'id',
            'InsertUserID' => 'user_id',
            'CategoryID' => 'group_id',
            'Name' => 'name',
            'Body' => 'body',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            //'FirstCommentID' => 'first_post_id',
            //'LastCommentID' => 'last_post_id',
            //'DateLastComment' => 'last_posted_at',
            //'LastCommentUserID' => 'last_posted_user_id',
            'CountComments' => 'total_comments',
            //'Announce' => 'status',
            //'Closed' => '',
        ];
        $filters = [];
        $query = $port->targetQB()
            ->from('Discussion')
            ->select();

        $port->import('discussions', $query, $structure, $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $structure = [];
        $map = [
            'CommentID' => 'id',
            'DiscussionID' => 'discussion_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'Body' => 'body'
        ];
        $filters = [];
        $query = $port->targetQB()
            ->from('Comment')
            ->select();

        // @todo !hasDiscussionBody support

        $port->import('posts', $query, $structure, $map, $filters);
    }
}
