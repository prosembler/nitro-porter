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
    public const SUPPORTED = [
        'name' => 'PunBB 1',
        'defaultTablePrefix' => 'punbb_',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'punbb',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 0,
            'Signatures' => 1,
            'Attachments' => 1,
            'Bookmarks' => 0,
            'Badges' => 0,
            'UserNotes' => 0,
            'Ranks' => 0,
            'Groups' => 0,
            'Tags' => 1,
        ]
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
     * @var array Required tables => columns
     */
    public array $sourceTables = array();

    /**
     * Forum-specific export format
     *
     */
    public function run(): void
    {
        $this->cdn = ''; //$this->param('cdn', '');
        /*if ($avatarPath = $this->param('avatars-source', false)) {
            if (!$avatarPath = realpath($avatarPath)) {
                echo "Unable to access path to avatars: $avatarPath\n";
                exit(1);
            }
            $this->avatarPath = $avatarPath;
        }*/

        $this->users();
        $this->roles();
        $this->signatures();

        $this->categories();
        $this->discussions();
        $this->comments();
        $this->tags();
        $this->attachments();
    }

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
     *
     * @param  string $value Current value
     * @param  string $field Current field
     * @param  array  $row   Contents of the current record.
     * @return string|null Return the supplied value if the record's file is an image. Return null otherwise
     * @see    Migration::writeTableToFile
     *
     */
    public function filterThumbnailData($value, $field, $row): ?string
    {
        if (strpos(strtolower($row['file_mime_type']), 'image/') === 0) {
            return $value;
        } else {
            return null;
        }
    }

    /**
     */
    protected function attachments(): void
    {
        if ($this->hasInputSchema('attach_files')) {
            // Media.
            $media_Map = array(
                'id' => 'MediaID',
                'filename' => 'Name',
                'file_mime_type' => 'Type',
                'size' => 'Size',
                'owner_id' => 'InsertUserID',
                'thumb_path' => array('Column' => 'ThumbPath', 'Filter' => array($this, 'filterThumbnailData')),
                'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
            );
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

    /**
     */
    protected function tags(): void
    {
        if ($this->hasInputSchema('tags')) {
            $tag_Map = array(
                'id' => 'TagID',
                'tag' => 'Name'
            );
            $this->export('Tag', "SELECT * FROM :_tags", $tag_Map);

            $tagDiscussionMap = array(
                'topic_id' => 'DiscussionID',
                'tag_id' => 'TagID'
            );
            $this->export('TagDiscussion', "SELECT * FROM :_topic_tags", $tagDiscussionMap);
        }
    }

    /**
     */
    protected function comments(): void
    {
        $comment_Map = array(
            'id' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'poster_id' => 'InsertUserID',
            'poster_ip' => 'InsertIPAddress',
            'message' => 'Body'
        );
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

    /**
     */
    protected function discussions(): void
    {
        $discussion_Map = array(
            'id' => 'DiscussionID',
            'poster_id' => 'InsertUserID',
            'poster_ip' => 'InsertIPAddress',
            'closed' => 'Closed',
            'sticky' => 'Announce',
            'forum_id' => 'CategoryID',
            'subject' => 'Name',
            'message' => 'Body'

        );
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

    /**
     */
    protected function categories(): void
    {
        $category_Map = array(
            'id' => 'CategoryID',
            'forum_name' => 'Name',
            'forum_desc' => 'Description',
            'disp_position' => 'Sort',
            'parent_id' => 'ParentCategoryID'
        );
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

    /**
     */
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

    /**
     */
    protected function roles(): void
    {
        $role_Map = array(
            'g_id' => 'RoleID',
            'g_title' => 'Name'
        );
        $this->export('Role', "SELECT * FROM :_groups", $role_Map);

        // UserRole.
        $userRole_Map = array(
            'id' => 'UserID',
            'group_id' => 'RoleID'
        );
        $this->export(
            'UserRole',
            "SELECT
                    CASE u.group_id WHEN 2 THEN 0 ELSE id END AS id,
                    u.group_id
                FROM :_users u",
            $userRole_Map
        );
    }

    /**
     */
    protected function users(): void
    {
        $user_Map = array(
            'AvatarID' => array('Column' => 'Photo', 'Filter' => array($this, 'getAvatarByID')),
            'id' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            //'timezone' => 'HourOffset',
            'registration_ip' => 'InsertIPAddress',
            'PasswordHash' => 'Password'
        );
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
