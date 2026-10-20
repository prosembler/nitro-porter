<?php

/**
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Target;

use Porter\Component;
use Porter\Target;
use Porter\Transformation;

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

    /** Check for issues that will break the import. */
    public function validate(): void
    {
        //
    }

    protected function users(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'users',
                data: 'User',
                map: [
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
                ],
                filters: [
                    //'field' => \Porter\Filter\EmptyToZero::class,
                ],
            ),
        ]);
    }

    /** 'Groups' in Discourse. */
    protected function roles(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'groups',
                data: 'Role',
                map: [
                    'RoleID' => 'id',
                    'Name' => 'name',
                    'DateInserted' => 'created_at',
                    'DateUpdated' => 'updated_at',
                ],
            ),
            new Transformation(
                outputSchemaName: 'group_users',
                data: 'UserRole',
                map: [
                    'UserID' => 'user_id',
                    'RoleID' => 'group_id',
                ],
            ),
        ]);
    }

    protected function categories(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'categories',
                data: $this->porterQB()->from('Category')->select()
                    ->where('CategoryID', '!=', -1), // Ignore Vanilla's root category.
                map: [
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
                ],
                filters: [
                    'CountDiscussions' => \Porter\Filter\EmptyToZero::class,
                ],
            ),
        ]);
    }

    /** 'Topics' in Discourse. */
    protected function discussions(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'topics',
                data: 'Discussion',
                map: [
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
                ],
            ),
        ]);
    }

    /** 'Posts' in Discourse. */
    protected function comments(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'posts',
                data: 'Comment',
                map: [
                    'CommentID' => 'id',
                    'InsertUserID' => 'user_id',
                    'DiscussionID' => 'topic_id',
                    'Body' => 'raw',  // -> 'cooked',
                    'DateInserted' => 'created_at',
                    'DateUpdated' => 'updated_at',
                    'DateDeleted' => 'deleted_at',
                    'Score' => 'score',
                ],
                filters: [
                    //'field' => \Porter\Filter\EmptyToZero::class,
                ],
            ),
        ]);
    }

    protected function reactions(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'discourse_reactions_reactions',
                data: $this->porterQB()->from('UserTag', 'ut')->select()
                    ->leftJoin('Tag', 'Tag.TagID', '=', 'ut.TagID')
                    ->whereIn('ut.RecordType', ['Discussion', 'Comment']),
                map: [
                    'UserID' => 'user_id',
                    'RecordID' => 'post_id',
                    'RecordType' => 'reaction_type',
                    //reaction_value', reaction_users_count',
                    'DateInserted' => 'created_at',
                ],
                filters: [
                    'field' => \Porter\Filter\EmptyToZero::class,
                ],
            ),
        ]);
    }

    /** 'Uploads' in Discourse. */
    protected function attachments(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'uploads',
                data: 'Media',
                map: [
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
                ],
            ),
        ]);
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
