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
    public const array INFO = [
        'name' => 'Discourse',
        'defaultTablePrefix' => '',
        //'avatarPath' => '',
        //'attachmentPath' => '',
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
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
        $this->import('users', $query, $this->getSchema('users'), $map, $filters);
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
        $this->import('groups', $query, $this->getSchema('groups'), $map);

        // User Role.
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $this->porterQB()->from('UserRole')
            ->select();
        $this->import('group_users', $query, $this->getSchema('group_users'), $map);
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
        $this->import('categories', $query, $this->getSchema('categories'), $map, $filters);
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
        $this->import('topics', $query, $this->getSchema('topics'), $map);
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
        $this->import('posts', $query, $this->getSchema('posts'), $map);
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
        $this->import('discourse_reactions_reactions', $query, $this->getSchema('discourse_reactions_reactions'), $map);


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
        $this->import('uploads', $query, $this->getSchema('uploads'), $map);
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
     * @see self::INFO [attachmentPath]
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
     * @see self::INFO [avatarPath]
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
