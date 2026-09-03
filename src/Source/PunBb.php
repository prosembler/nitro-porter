<?php

/**
 * PunBB exporter tool
 *
 * @author  Todd Burry
 */

namespace Porter\Source;

use Porter\Source;

class PunBb extends Source
{
    public const array INFO = [
        'name' => 'PunBB 1',
        'defaultTablePrefix' => 'punbb_',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'punbb',
    ];

    protected function attachments(): void
    {
        if ($this->hasInputSchema('attach_files')) {
            // Media.
            $map = [
                'id' => 'MediaID',
                'filename' => 'Name',
                'file_mime_type' => 'Type',
                'size' => 'Size',
                'owner_id' => 'InsertUserID',
                'uploaded_at' => 'DateInserted',
                'thumb_path' => 'ThumbPath',
                'thumb_width' => 'ThumbWidth',
            ];
            $filters = [
                'thumb_path' => \Porter\Filter\NullIfNotImage::class,
                'thumb_width' => \Porter\Filter\NullIfNotImage::class,
                'uploaded_at' => \Porter\Filter\UnixtimeToDate::class,
            ];
            $this->export(
                'Media',
                "select f.*,
                        file_mime_type as Mime,
                        concat('FileUpload/', f.file_path) as Path,
                        concat('FileUpload/', f.file_path) as thumb_path,
                        128 as thumb_width,
                        f.uploaded_at,
                        case when post_id is null then 'Discussion' else 'Comment' end as ForeignTable,
                        coalesce(post_id, topic_id) as ForieignID
                    from :_attach_files f",
                $map,
                $filters
            );
        }
    }

    protected function tags(): void
    {
        if ($this->hasInputSchema('tags')) {
            $tag_Map = [
                'id' => 'TagID',
                'tag' => 'Name'
            ];
            $this->export('Tag', "SELECT * FROM :_tags", $tag_Map);

            $tagDiscussionMap = [
                'topic_id' => 'DiscussionID',
                'tag_id' => 'TagID'
            ];
            $this->export('TagDiscussion', "SELECT * FROM :_topic_tags", $tagDiscussionMap);
        }
    }

    protected function comments(): void
    {
        $comment_Map = [
            'id' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'poster_id' => 'InsertUserID',
            'poster_ip' => 'InsertIPAddress',
            'message' => 'Body',
            'Format=BBCode',
        ];
        $this->export(
            'Comment',
            "SELECT p.*,
                    from_unixtime(p.posted) AS DateInserted,
                    from_unixtime(p.edited) AS DateUpdated,
                    eu.id AS UpdateUserID
                FROM :_topics t
                JOIN :_posts p
                    ON t.id = p.topic_id
                LEFT JOIN :_users eu
                    ON eu.username = p.edited_by
                WHERE p.id <> t.first_post_id;",
            $comment_Map
        );
    }

    protected function discussions(): void
    {
        $discussion_Map = [
            'id' => 'DiscussionID',
            'poster_id' => 'InsertUserID',
            'poster_ip' => 'InsertIPAddress',
            'closed' => 'Closed',
            'sticky' => 'Announce',
            'forum_id' => 'CategoryID',
            'subject' => 'Name',
            'message' => 'Body'

        ];
        $this->export(
            'Discussion',
            "SELECT t.*,
                    from_unixtime(p.posted) AS DateInserted,
                    p.poster_id,
                    p.poster_ip,
                    p.message,
                    from_unixtime(p.edited) AS DateUpdated,
                    eu.id AS UpdateUserID,
                    'BBCode' AS Format
                FROM :_topics t
                LEFT JOIN :_posts p ON t.first_post_id = p.id
                LEFT JOIN :_users eu ON eu.username = p.edited_by",
            $discussion_Map
        );
    }

    protected function categories(): void
    {
        $category_Map = [
            'id' => 'CategoryID',
            'forum_name' => 'Name',
            'forum_desc' => 'Description',
            'disp_position' => 'Sort',
            'parent_id' => 'ParentCategoryID'
        ];
        $this->export(
            'Category',
            "SELECT id,
                forum_name,
                forum_desc,
                disp_position,
                cat_id * 1000 AS parent_id
            FROM :_forums f
            UNION
            SELECT id * 1000,
                cat_name,
                '',
                disp_position,
                NULL
            FROM :_categories",
            $category_Map
        );
    }

    protected function signatures(): void
    {
        $this->export(
            'UserMeta',
            "select u.id as UserID,
                   'Plugin.Signatures.Format' AS Name,
                   'BBCode' as Value
                from :_users u
                where u.signature is not null and u.signature != ''
                union
                select u.id as UserID,
                    'Plugin.Signatures.Sig' AS Name,
                    signature as Value
                from :_users u
                where u.signature is not null and u.signature !=''"
        );
    }

    protected function roles(): void
    {
        $role_Map = [
            'g_id' => 'RoleID',
            'g_title' => 'Name'
        ];
        $this->export('Role', "SELECT * FROM :_groups", $role_Map);

        // UserRole.
        $userRole_Map = [
            'id' => 'UserID',
            'group_id' => 'RoleID'
        ];
        $this->export(
            'UserRole',
            "SELECT CASE u.group_id WHEN 2 THEN 0 ELSE id END AS id, u.group_id FROM :_users u",
            $userRole_Map
        );
    }

    protected function users(): void
    {
        $map = [
            'id' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            'PasswordHash' => 'Password'
        ];
        $filters = [
            'Photo' => \Porter\Filter\PunBbAvatarFileType::class,
        ];
        $this->export(
            'User',
            "SELECT u.*, u.id AS Photo,
                     concat(u.password, '$', u.salt) AS PasswordHash,
                     from_unixtime(registered) AS DateInserted,
                     from_unixtime(last_visit) AS DateLastActive
                FROM :_users u
                WHERE group_id <> 2",
            $map,
            $filters
        );
    }
}
