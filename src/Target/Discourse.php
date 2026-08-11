<?php

/**
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Target;

use Porter\Target;

/**
 *
 */
class Discourse extends Target
{
    public const array SUPPORTED = [
        'name' => 'Discourse',
        'defaultTablePrefix' => '',
        //'avatarPath' => '',
        //'attachmentPath' => '',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Categories' => 1,
            'Roles' => 1,
            'Attachments' => 1,
            'Avatars' => 1,
            'Reactions' => 1,
            'Tags' => 0, //
            'Bookmarks' => 0, //
            'Polls' => 0, //
            'Badges' => 0, //
            'Groups' => 0,
            'PrivateMessages' => 0,
        ]
    ];

    protected const FLAGS = [
        'hasDiscussionBody' => false,
    ];

    protected const SCHEMA_USERS = [
        'id' => 'int4',
        'username' => 'varchar(60)',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'name' => 'varchar',
        'last_posted_at' => 'timestamp',
        'active' => 'bool',
        'username_lower' => 'varchar(60)',
        'last_seen_at' => 'timestamp',
        'admin' => 'bool',
        'approved' => 'bool',
        'trust_level' => 'int4',
        'approved_by_id' => 'int',
        'approved_at' => 'timestamp',
        'first_seen_at' => 'timestamp',
    ];

    protected const SCHEMA_DISCUSSIONS = [
        'id' => 'int4',
        'title' => 'varchar',
        'last_posted_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'views' => 'int4',
        'posts_count' => 'int4',
        'user_id' => 'int4',
        'last_post_user_id' => 'int4',
        'highest_post_number' => 'int4',
        'category_id' => 'int',
        'closed' => 'bool',
        'archived' => 'bool',
        'pinned_at' => 'timestamp',
        'slug' => 'varchar',
        'fancy_title' => 'varchar',
        'pinned_globally' => 'bool',
    ];
    protected const SCHEMA_COMMENTS = [
        'id' => 'int4',
        'user_id' => 'int4',
        'topic_id' => 'int4',
        'post_number' => 'int4',
        'raw' => 'text',
        'cooked' => 'text',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'reply_to_post_number' => 'int4',
        'deleted_at' => 'timestamp',
        'score' => 'float8',
        'post_type' => 'tinyint',
        'sort_order' => 'int4',
        'user_deleted' => 'bool',
        'cook_method' => 'int4', // 1
    ];

    protected const SCHEMA_CATEGORIES = [
        'id' => 'int4',
        'name' => 'varchar',
        'topic_id' => 'int4',
        'parent_category_id' => 'int4',
        'topic_count' => 'int',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_id' => 'int4',
        'slug' => 'varchar',
        'description' => 'text',
        'post_count' => 'int4',
        'latest_post_id' => 'int4',
        'latest_topic_id' => 'int4',
        'position' => 'int4',
        'sort_order' => 'int4',
        'emoji' => 'varchar',
    ];

    protected const SCHEMA_ROLES = [
        'id' => 'int4',
        'name' => 'varchar',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_count' => 'int4',
        'full_name' => 'varchar',
        'visibility_level' => 'int4',
    ];

    protected const SCHEMA_USER_ROLES = [
        'user_id' => 'int4',
        'group_id' => 'int4',
    ];

    protected const SCHEMA_ATTACHMENTS = [
        'id' => 'int4',
        'user_id' => 'int4',
        'original_filename' => 'varchar',
        'filesize' => 'int8',
        'width' => 'int4',
        'height' => 'int4',
        'url' => 'varchar',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'sha1' => 'varchar(40)',
        'origin' => 'varchar(2000)',
        'extension' => 'varchar(10)',
        'animated' => 'bool',
    ];

    protected const SCHEMA_REACTIONS = [
        'id' => 'int4',
        'user_id' => 'int4',
        'post_id' => 'int4',
        'reaction_type' => 'int4',
        'reaction_value' => 'varchar',
        'reaction_users_count' => 'int',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    protected const SCHEMA_REACTION_USERS = [
        'id' => 'int4',
        'user_id' => 'int4',
        'post_id' => 'int4',
        'reaction_id' => 'int4',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
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
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            //name', //??
            //'last_posted_at', //not in Porter
            //'Deleted' => 'active',
            //'username_lower',
            'DateLastActive' => 'last_seen_at',
            //'Confirmed' => 'approved',
            'DateFirstVisit' => 'first_seen_at',
        ];
        $filters = [];
        $query = $this->porterQB()->from('User')
            ->select();
        $this->import('users', $query, self::SCHEMA_USERS, $map, $filters);
    }

    /**
     * 'Groups' in Discourse.
     */
    protected function roles(): void
    {
        // Roles.
        $map = [
            'RoleID' => 'id',
            'Name' => 'name',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
        ];
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
        $this->import('group_users', $query, self::SCHEMA_USER_ROLES, $map);
    }

    protected function categories(): void
    {
        $map = [
            'CategoryID' => 'id',
            'Name' => 'name',
            'ParentCategoryID' => 'parent_category_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'InsertUserID' => 'user_id',
            'UrlCode' => 'slug',
            'Description' => 'description',
            'CountDiscussions' => 'topic_count',
            'CountComments' => 'post_count',
        ];
        $filters = [
            'CountDiscussions' => 'emptyToZero',
        ];
        $query = $this->porterQB()->from('Category')
            ->select()
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.
        $this->import('categories', $query, self::SCHEMA_CATEGORIES, $map, $filters);
    }

    /**
     * 'Topics' in Discourse.
     */
    protected function discussions(): void
    {
        $map = [
            'DiscussionID' => 'id',
            'Name' => 'title',
            'InsertUserID' => 'user_id',
            'CategoryID' => 'category_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'CountViews' => 'views',
            'CountComments' => 'posts_count',
            'DateLastComment' => 'last_posted_at',
            'LastCommentUserID' => 'last_post_user_id',
            //'LastCommentID' => 'highest_post_number', //not necessarily the same thing
            //'Closed' => 'closed', tinyint->bool
            //'archived',
            //'Announce' => 'pinned_globally', //=2
        ];
        $query = $this->porterQB()->from('Discussion')
            ->select();
        $this->import('topics', $query, self::SCHEMA_DISCUSSIONS, $map);
    }

    /**
     * 'Posts' in Discourse.
     */
    protected function comments(): void
    {
        $map = [
            'CommentID' => 'id',
            'InsertUserID' => 'user_id',
            'DiscussionID' => 'topic_id',
            'Body' => 'raw',  // -> 'cooked',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'DateDeleted' => 'deleted_at',
            'Score' => 'score',
        ];
        $query = $this->porterQB()->from('Comment')
            ->select();
        $this->import('posts', $query, self::SCHEMA_COMMENTS, $map);
    }

    protected function reactions(): void
    {
        $map = [
            'UserID' => 'user_id',
            'RecordID' => 'post_id',
            'RecordType' => 'reaction_type',
            //reaction_value',
            //reaction_users_count',
            'DateInserted' => 'created_at',
        ];
        $query = $this->porterQB()->from('UserTag ut')
            ->leftJoin('Tag t', 't.TagID', '=', 'ut.TagID')
            ->select()
            ->whereIn('ut.RecordType', ['Discussion', 'Comment']);
        $this->import('discourse_reactions_reactions', $query, self::SCHEMA_REACTIONS, $map);


        //$this->import('discourse_reactions_reaction_users', $query, self::SCHEMA_REACTION_USER, $map);
    }

    /**
     * 'Uploads' in Discourse.
     */
    protected function attachments(): void
    {
        $map = [
            'MediaID' => 'id',
            'InsertUserID' => 'user_id,',
            'Name' => 'original_filename',
            'Filesize' => 'filesize',
            'Width' => 'width',
            'Height' => 'height',
            'Path' => 'url', //='/uploads/default/original/1X/b75c33ea141d3a8d82fda3117d09fe33a664fec9.png'
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            //'Type' => 'extension',
            //'animated',
        ];
        $query = $this->porterQB()->from('Media')->select();
        $this->import('uploads', $query, self::SCHEMA_ATTACHMENTS, $map);
    }

    protected function avatars(): void
    {
        // upload_reference.target_type = 'UserAvatar'
        // user_id => target_id',
        //user_avatars.user_id, gravatar_upload_id=upload_id, custom_upload_id=NULL (or inverse)
        //last_gravatar_download_attempt, created_at, updated_at
    }

    /**
     * Assign a new location for message file attachments.
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
