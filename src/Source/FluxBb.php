<?php

/**
 * FluxBB exporter tool
 *
 * @author  Francis Caisse
 */

namespace Porter\Source;

use Porter\Source;

class FluxBb extends Source
{
    public const array INFO = [
        'name' => 'FluxBB 1',
        'defaultTablePrefix' => '',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'punbb', // FluxBB is a fork of punbb and the password works.
    ];

    protected function users(): void
    {
        $map = [
            'id' => 'UserID',
            'avatar' => 'Photo',
            'username' => 'Name',
            'email' => 'Email',
            'password' => 'Password',
            'registered' => 'DateInserted',
            'last_visit' => 'DateLastActive',
        ];
        $filters = [
            'avatar' => \Porter\Filter\FluxBbAvatar::class,
            'registered' => \Porter\Filter\UnixtimeToDate::class,
            'last_visit' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export('User', "select * from :_users u where group_id <> 2", $map, $filters);
    }

    protected function roles(): void
    {
        $this->export('Role', "select g_id as RoleID, g_title as Name from :_groups");
        $this->export('UserRole', "select u.id as UserID, u.group_id as RoleID from :_users u");
    }

    protected function signatures(): void
    {
        $map = [
            'id' => 'UserID',
            'signature' => 'Value',
            'Name=Plugin.Signatures.Sig',
        ];
        $this->export('UserMeta', "select * from :_users u where u.signature is not null", $map);
    }

    protected function categories(): void
    {
        $map = [
            'id' => 'CategoryID',
            'forum_name' => 'Name',
            'forum_desc' => 'Description',
            'disp_position' => 'Sort',
        ];
        $query = "select *, cat_id*1000 as ParentCategoryID from :_forums f";
        $this->export('Category', $query, $map);

        $map = [
            'cat_name' => 'Name',
            'disp_position' => 'Sort',
        ];
        $query = "select *, id*1000 as CategoryID from :_categories";
        $this->export('Category', $query, $map);
    }

    protected function discussions(): void
    {
        $map = [
            'poster_id' => 'InsertUserID',
            'forum_id' => 'CategoryID',
            'subject' => 'Name',
            'message' => 'Body',
            'posted' => 'DateInserted',
            'updated' => 'DateUpdated',
            'closed' => 'Closed',
            'sticky' => 'Announce',
            'Format=BBCode',
        ];
        $filters = [
            'posted' => \Porter\Filter\UnixtimeToDate::class,
            'edited' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $query = "select p.poster_id, t.forum_id, t.subject, p.posted, p.edited, t.closed, t.sticky,
                    t.id as DiscussionID, u.id as UpdateUserID
                from :_topics t
                left join :_posts p on t.first_post_id = p.id
                left join :_users u on u.username = p.edited_by";
        $this->export('Discussion', $query, $map, $filters);
    }

    protected function comments(): void
    {
        $map = [
            'id' => 'CommentID',
            'poster_id' => 'InsertUserID',
            'message' => 'Body',
            'Format=BBCode',
            'posted' => 'DateInserted',
            'edited' => 'DateUpdated',
        ];
        $filters  = [
            'posted' => \Porter\Filter\UnixtimeToDate::class,
            'edited' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $query = "select p.*, u.id as UpdateUserID
                from :_topics t
                join :_posts p on t.id = p.topic_id
                left join :_users u on u.username = p.edited_by
                where p.id <> t.first_post_id";
        $this->export('Comment', $query, $map, $filters);
    }

    protected function tags(): void
    {
        if (!$this->hasInputSchema('tags')) {
            return;
        }
        $map = [
            'id' => 'TagID',
            'tag' => 'Name',
        ];
        $this->export('Tag', "select id, tag from :_tags", $map);

        $map  = [
            'topic_id' => 'DiscussionID',
            'tag_id' => 'TagID',
        ];
        $this->export('TagDiscussion', "select topic_id, tag_id from :_topic_tags", $map);
    }

    protected function attachments(): void
    {
        if (!$this->hasInputSchema('attach_files')) {
            return;
        }
        $map = [
            'owner_id' => 'InsertUserID',
            'thumb_path' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
            'id' => 'MediaID',
            'filename' => 'Name',
            'size' => 'Size',
            'type' => 'Type',
            'uploaded_at' => 'DateInserted',
        ];
        $filters  = [
            'thumb_path' => \Porter\Filter\NullIfNotImage::class,
            'thumb_width' => \Porter\Filter\NullIfNotImage::class,
            'uploaded_at' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $query = "select f.*,
                    file_mime_type as Mime,
                    concat('FileUpload/', f.file_path) as Path,
                    concat('FileUpload/', f.file_path) as thumb_path,
                    128 as thumb_width,
                    case when f.post_id is null then 'Discussion' else 'Comment' end as ForeignTable,
                    coalesce(f.post_id, f.topic_id) as ForeignID
                from :_attach_files f";
        $this->export('Media', $query, $map, $filters);
    }
}
