<?php

/**
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Target;

use Porter\Component;
use Porter\Target;
use Porter\Transformation;

/**
 *
 */
class Agorakit extends Target
{
    public const array INFO = [
        'name' => 'Agorakit',
        'defaultTablePrefix' => '',
        'avatarPath' => 'storage/app/users',
        'attachmentPath' => 'storage/app/import',
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => true,
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
                    'FullName' => 'name',
                    'Email' => 'email',
                    'Password' => 'password',
                    'Confirmed' => 'verified',
                    'DateInserted' => 'created_at',
                    'Admin' => 'admin',
                ],
                filters: [],
            )
        ]);
    }

    /** 'Groups' in Agorakit. */
    protected function roles(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'groups',
                data: 'Role',
                map: [
                    'RoleID' => 'id',
                    'Name' => 'name',
                    'Description' => 'body',
                ],
                filters: [],
            ),
            new Transformation(
                outputSchemaName: 'membership',
                data: 'UserRole',
                map: [
                    'UserID' => 'user_id',
                    'RoleID' => 'group_id',
                ],
                filters: [],
            )
        ]);
    }

    protected function categories(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'tags',
                data: $this->selectFrom('Category')->where('CategoryID', '!=', -1),
                map: [
                    'CategoryID' => 'id',
                    'Name' => 'name',
                    'Description' => 'description',
                    'ParentCategoryID' => 'parent_id',
                    'Sort' => 'position',
                    'CountDiscussions' => 'discussion_count',
                ],
                filters: [
                    'CountDiscussions' => \Porter\Filter\EmptyToZero::class,
                ],
            )
        ]);
    }

    protected function discussions(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'discussions',
                data: 'Discussion',
                map: [
                    'DiscussionID' => 'id',
                    'InsertUserID' => 'user_id',
                    'CategoryID' => 'group_id',
                    'Name' => 'name',
                    'Body' => 'body',
                    'DateInserted' => 'created_at',
                    'DateUpdated' => 'updated_at',
                    'CountComments' => 'total_comments',
                    //'Announce'/'Closed' => 'status',
                ],
                filters: [],
            )
        ]);
    }

    /** 'Posts' in Agorakit. */
    protected function comments(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'posts',
                data: 'Comment',
                map: [
                    'CommentID' => 'id',
                    'DiscussionID' => 'discussion_id',
                    'InsertUserID' => 'user_id',
                    'DateInserted' => 'created_at',
                    'DateUpdated' => 'updated_at',
                    'Body' => 'body'
                ],
                filters: [],
            )
        ]);
    }

    /**
     * 2026-07
     * Agorakit only supports a subset of named emoji reactions hard-coded to /images/reactions/{type}.png
     * so you'd need to pass those files along as well.
     */
    protected function reactions(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'reactions',
                data: $this->selectFrom('UserTag ut')
                    ->leftJoin('Tag t', 't.TagID', '=', 'ut.TagID')
                    ->whereIn('ut.RecordType', ['Discussion', 'Comment']),
                map: [
                    'UserID' => 'user_id',
                    'RecordID' => 'reactable_id',
                    'RecordType' => 'reactable_type',
                    'Name' => 'type', // Expects /images/reactions/{filename}.png.
                    'DateInserted' => 'created_at',
                ],
                filters: [],
            )
        ]);
    }

    /** 'Files' in Agorakit. */
    protected function attachments(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'files',
                data: 'Media',
                map: [
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
                    //'original_extension', 'original_filename', 'group_id',
                ],
                filters: [],
            )
        ]);
    }

    /** Avatars are auto-detected by filename in Agorakit. */
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
     *
     * Format: {approot}/storage/app/users/{user_id}/cover.jpg
     * We cannot convert to .jpg, so reuse existing file extension.
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
