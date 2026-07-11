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
        'avatarPath' => 'storage/app/users',
        'attachmentPath' => 'storage/app/import',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Categories' => 1,
            'Roles' => 1,
            'Attachments' => 1,
            'Avatars' => 1,
            'Reactions' => 0, // Partial
            // @todo Figure out support options.
            'Tags' => 0,
            'Groups' => 0,
            'Bookmarks' => 0,
            'Polls' => 0,
            'Badges' => 0,
            'PrivateMessages' => 0,
        ]
    ];

    protected const FLAGS = [
        'hasDiscussionBody' => true,
    ];

    protected const SCHEMA_USERS = [
        'id' => 'int',
        'name' => 'varchar(100)',
        'username' => 'varchar(100)',
        'email' => 'varchar(100)',
        'verified' => 'tinyint',
        'password' => 'varchar(100)',
        'created_at' => 'datetime',
        'admin' => 'tinyint',
    ];

    protected const SCHEMA_DISCUSSIONS = [
        'id' => 'int',
        'group_id' => 'int',
        'user_id' => 'int',
        'status' => 'int',
        'name' => 'varchar(100)',
        'body' => 'text',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'total_comments' => 'int',
    ];
    protected const SCHEMA_COMMENTS = [
        'id' => 'int',
        'discussion_id' => 'int',
        'user_id' => 'int',
        'body' => 'text',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected const SCHEMA_CATEGORIES = [
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

    protected const SCHEMA_ROLES = [
        'id' => 'int',
        'name' => 'varchar(100)',
        'body' => 'text',
    ];

    protected const SCHEMA_USER_ROLES = [
        'user_id' => 'int',
        'group_id' => 'int',
    ];

    protected const SCHEMA_ATTACHMENTS = [
        'id' => 'int',
        'parent_id' => 'int',
        'group_id' => 'int',
        'user_id' => 'int',
        'item_type' => 'int',
        'filesize' => 'int',
        'status' => 'int',
        'name' => 'varchar(100)',
        'mime' => 'varchar(100)',
        'path' => 'text',
        'original_filename' => 'text',
        'original_extension' => 'text',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected const SCHEMA_REACTIONS = [
        'id' => 'int',
        'user_id' => 'int',
        'reactable_id' => 'int',
        'reactable_type' => 'varchar(100)',
        'type' => 'varchar(100)',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Check for issues that will break the import.
     */
    public function validate(): void
    {
        //
    }

    protected function users(): void
    {
        $map = [
            'UserID' => 'id',
            'Name' => 'username',
            'FullName' => 'name',
            'Email' => 'email',
            'Password' => 'password',
            'Confirmed' => 'verified',
            'DateInserted' => 'created_at',
            'Admin' => 'admin',
        ];
        $filters = [];
        $query = $this->porterQB()->from('User')
            ->select();
        $this->import('users', $query, self::SCHEMA_USERS, $map, $filters);
    }

    /**
     * 'Groups' in Agorakit.
     */
    protected function roles(): void
    {
        // Roles.
        $map = [];
        $query = $this->porterQB()->from('Role')
            ->select();
        $this->import('groups', $query, self::SCHEMA_ROLES, $map);

        // User Role.
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $this->porterQB()->from('UserRole')
            ->select();
        $this->import('membership', $query, self::SCHEMA_USER_ROLES, $map);
    }

    protected function categories(): void
    {
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
        $query = $this->porterQB()->from('Category')
            ->select()
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.
        $this->import('tags', $query, self::SCHEMA_CATEGORIES, $map, $filters);
    }

    protected function discussions(): void
    {
        $map = [
            'DiscussionID' => 'id',
            'InsertUserID' => 'user_id',
            'CategoryID' => 'group_id',
            'Name' => 'name',
            'Body' => 'body',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'CountComments' => 'total_comments',
            //'Announce'/'Closed' => 'status',
        ];
        $query = $this->porterQB()->from('Discussion')
            ->select();
        $this->import('discussions', $query, self::SCHEMA_DISCUSSIONS, $map);
    }

    /**
     * 'Posts' in Agorakit,
     */
    protected function comments(): void
    {
        $map = [
            'CommentID' => 'id',
            'DiscussionID' => 'discussion_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'Body' => 'body'
        ];
        $query = $this->porterQB()->from('Comment')
            ->select();
        $this->import('posts', $query, self::SCHEMA_COMMENTS, $map);
    }

    /**
     * 2026-07
     * Agorakit only supports a subset of named emoji reactions hard-coded to /images/reactions/{type}.png
     * so you'd need to pass those files along as well.
     */
    protected function reactions(): void
    {
        $map = [
            'UserID' => 'user_id',
            'RecordID' => 'reactable_id',
            'RecordType' => 'reactable_type',
            'Name' => 'type', // Expects /images/reactions/{filename}.png.
            'DateInserted' => 'created_at',
        ];
        $query = $this->porterQB()->from('UserTag ut')
            ->leftJoin('Tag t', 't.TagID', '=', 'ut.TagID')
            ->select()
            ->whereIn('ut.RecordType', ['Discussion', 'Comment']);
        $this->import('reactions', $query, self::SCHEMA_REACTIONS, $map);
    }

    /**
     * 'Files' in Agorakit.
     */
    protected function attachments(): void
    {
        $map = [
            'MediaID' => 'id',
            'ForeignID' => 'parent_id',
            'InsertUserID' => 'user_id',
            'ForeignTable' => 'item_type',
            'Size' => 'filesize',
            //'Active' => 'status', // filter required?
            'Name' =>  'name',
            'Type' => 'mime',
            'Path' => 'path',
            'DateInserted' => 'created_at',
            //'original_extension'
            //'original_filename'
            //'group_id',
        ];
        $query = $this->porterQB()->from('Media')->select();
        $this->import('files', $query, self::SCHEMA_ATTACHMENTS, $map);
    }

    /**
     * Avatars are auto-detected by filename in Agorakit.
     */
    protected function avatars(): void
    {
        // noop
    }

    /**
     * Assign a new location for message file attachments.
     *
     * Format: {approot}/storage/app/groups/{group_id}/files/{file_id}/{datestamp}-{originalname}
     * Use a generic 'imports' folder instead of attempting to divvy by group.
     * @see self::filemap()
     * @see self::SUPPORTED [attachmentPath]
     */
    protected function mapAttachments(string $fileTarget): int
    {
        $rows = 0;
        $attachments = $this->porterQB()->from('Media')
            ->select(['MediaID'])
            ->selectRaw("concat('{$fileTarget}/', Path) as TargetFullPath")
            ->whereNotNull("Path")
            ->get();
        foreach ($attachments as $attachment) {
            $rows += $this->dbOutput()->affectingStatement("update `PORT_Media`
                set TargetFullPath = " . $this->dbOutput()->escape($attachment->TargetFullPath) . "
                where MediaID = {$attachment->MediaID}");
        }

        return $rows;
    }

    /**
     * Assign a new location for user photos / avatars.
     *
     * Format: {approot}/storage/app/users/{user_id}/cover.jpg
     * We cannot convert to .jpg, so reuse existing file extension.
     * @see self::filemap()
     * @see self::SUPPORTED [avatarPath]
     */
    protected function mapAvatars(string $fileTarget): int
    {
        $rows = 0;
        $avatars = $this->porterQB()->from('User')
            ->select(['UserID'])
            ->selectRaw("concat('{$fileTarget}', UserID, '/cover.', SUBSTRING_INDEX(Photo,'.',-1)) 
                as TargetAvatarFullPath")
            ->whereNotNull("SourceAvatarFullPath")
            ->get();
        foreach ($avatars as $avatar) {
            $rows += $this->dbOutput()->affectingStatement("update `PORT_User`
                set TargetAvatarFullPath = " . $this->dbOutput()->escape($avatar->TargetAvatarFullPath) . "
                where UserID = {$avatar->UserID}");
        }

        return $rows;
    }
}
