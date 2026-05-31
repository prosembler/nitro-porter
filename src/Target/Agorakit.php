<?php

/**
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Target;

use Porter\Target;

/**
 *
 */
class Agorakit extends Target
{
    public const array SUPPORTED = [
        'name' => 'Agorakit',
        'defaultTablePrefix' => '',
        //'avatarPath' => '',
        //'attachmentPath' => '',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Categories' => 1, // @todo TAGS
            'Roles' => 1, // @todo GROUPS
            'Attachments' => 1, // @todo FILES
            'Avatars' => 1,
            // @todo Figure out support options.
            'Tags' => 0,
            'Groups' => 0,
            'Reactions' => 0,
            'Bookmarks' => 0,
            'Polls' => 0,
            'Badges' => 0,
            'PrivateMessages' => 0,
        ]
    ];

    protected const FLAGS = [
        'hasDiscussionBody' => true,
    ];

    /** @var int Offset for inserting OP content into the posts table. */
    protected int $discussionPostOffset = 0;

    /**
     * Check for issues that will break the import.
     */
    public function validate(): void
    {
        //
    }

    protected function users(): void
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
            'Confirmed' => 'verified',
            //'Photo' => 'avatar_url',
            'DateInserted' => 'created_at',
            'Admin' => 'admin',
        ];
        $filters = [];
        $query = $this->porterQB()
            ->from('User')
            ->select();

        $this->import('users', $query, $structure, $map, $filters);
    }

    protected function roles(): void
    {
        $structure = [
            'id' => 'int',
            'name_singular' => 'varchar(100)',
        ];
        $map = [];
        $query = $this->porterQB()
            ->from('Role')
            ->select();

        $this->import('groups', $query, $structure, $map);

        // User Role.
        $structure = [
            'user_id' => 'int',
            'group_id' => 'int',
        ];
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $this->porterQB()
            ->from('UserRole')
            ->select();

        $this->import('group_user', $query, $structure, $map);
    }

    protected function categories(): void
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
        $query = $this->porterQB()
            ->from('Category')
            ->select()
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.

        $this->import('tags', $query, $structure, $map, $filters);
    }

    protected function discussions(): void
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
        $query = $this->porterQB()
            ->from('Discussion')
            ->select();

        $this->import('discussions', $query, $structure, $map, $filters);
    }

    protected function comments(): void
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
        $query = $this->porterQB()
            ->from('Comment')
            ->select();

        // @todo !hasDiscussionBody support

        $this->import('posts', $query, $structure, $map, $filters);
    }
}
