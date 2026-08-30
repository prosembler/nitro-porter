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

    /**
     * @var string Path to avatar images
     */
    protected string $avatarPath = '';

    /**
     * @var string CDN path prefix
     */
    protected string $cdn = '';

    /**
     * Take the user ID, avatar type value and generate a path to the avatar file.
     *
     * @param mixed $value Row field value.
     * @param string $field Name of the current field.
     * @param array $row All of the current row values.
     * @return null|string
     */
    public function getAvatarByID($value, $field, $row): ?string
    {
        if (!$this->avatarPath) {
            return null;
        }

        switch ($row['avatar']) {
            case 1:
                $extension = 'gif';
                break;
            case 2:
                $extension = 'jpg';
                break;
            case 3:
                $extension = 'png';
                break;
            default:
                return null;
        }
        $avatarFilename = "{$this->avatarPath}/{$value}.$extension";

        if (file_exists($avatarFilename)) {
            $avatarBasename = basename($avatarFilename);
            return "{$this->cdn}punbb/avatars/$avatarBasename";
        } else {
            return null;
        }
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     */
    public function filterThumbnailData(mixed $value, string $field, array $row): ?string
    {
        if (strpos(strtolower($row['file_mime_type']), 'image/') === 0) {
            return $value;
        } else {
            return null;
        }
    }

    protected function attachments(): void
    {
        if ($this->hasInputSchema('attach_files')) {
            // Media.
            $media_Map = [
                'id' => 'MediaID',
                'filename' => 'Name',
                'file_mime_type' => 'Type',
                'size' => 'Size',
                'owner_id' => 'InsertUserID',
                'thumb_path' => ['Column' => 'ThumbPath', 'Filter' => [$this, 'filterThumbnailData']],
                'thumb_width' => ['Column' => 'ThumbWidth', 'Filter' => [$this, 'filterThumbnailData']],
            ];
            $this->export(
                'Media',
                "select f.*,
                        concat({$this->cdn}, 'FileUpload/', f.file_path) as Path,
                        concat({$this->cdn}, 'FileUpload/', f.file_path) as thumb_path,
                        128 as thumb_width,
                        from_unixtime(f.uploaded_at) as DateInserted,
                        case when post_id is null then 'Discussion' else 'Comment' end as ForeignTable,
                        coalesce(post_id, topic_id) as ForieignID
                    from :_attach_files f",
                $media_Map
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
            'message' => 'Body'
        ];
        $this->export(
            'Comment',
            "SELECT p.*,
                    'BBCode' AS Format,
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
                LEFT JOIN :_posts p
                    ON t.first_post_id = p.id
                LEFT JOIN :_users eu
                    ON eu.username = p.edited_by",
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
            "SELECT
                id,
                forum_name,
                forum_desc,
                disp_position,
                cat_id * 1000 AS parent_id
            FROM :_forums f
            UNION
            SELECT
                id * 1000,
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
            "select
                   u.id as UserID,
                   'Plugin.Signatures.Format' AS Name,
                   'BBCode' as Value
                from :_users u
                where u.signature is not null
                and u.signature != ''
                union
                select
                    u.id as UserID,
                    'Plugin.Signatures.Sig' AS Name,
                    signature as Value
                from :_users u
                where u.signature is not null
                and u.signature !=''"
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
            "SELECT
                    CASE u.group_id WHEN 2 THEN 0 ELSE id END AS id,
                    u.group_id
                FROM :_users u",
            $userRole_Map
        );
    }

    protected function users(): void
    {
        $user_Map = [
            'AvatarID' => ['Column' => 'Photo', 'Filter' => [$this, 'getAvatarByID']],
            'id' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            //'timezone' => 'HourOffset',
            'registration_ip' => 'InsertIPAddress',
            'PasswordHash' => 'Password'
        ];
        $this->export(
            'User',
            "SELECT
                     u.*, u.id AS AvatarID,
                     concat(u.password, '$', u.salt) AS PasswordHash,
                     from_unixtime(registered) AS DateInserted,
                     from_unixtime(last_visit) AS DateLastActive
                FROM :_users u
                WHERE group_id <> 2",
            $user_Map
        );
    }
}
