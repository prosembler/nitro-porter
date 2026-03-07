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
    public const SUPPORTED = [
        'name' => 'FluxBB 1',
        'defaultTablePrefix' => '',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'punbb', // FluxBB is a fork of punbb and the password works.
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 0,
            'Signatures' => 1,
            'Attachments' => 1,
            'Bookmarks' => 0,
            'Tags' => 1,
        ]
    ];

    /**
     * @var bool Path to avatar images
     */
    protected $avatarPath = false;

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
     *@todo Project file size / export time and possibly break into multiple files
     *
     */
    public function run(): void
    {
        $this->cdn = ''; //$this->param('cdn', '');
        /*if ($this->avatarPath = '') { //$this->param('avatars-source')) {
            if (!$this->avatarPath = realpath($this->avatarPath)) {
                exit("Unable to access path to avatars: $this->avatarPath\n");
            }
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
            return "{$this->cdn}fluxbb/img/avatars/$avatarBasename";
        } else {
            return null;
        }
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     *
     * @access public
     * @param  string $value Current value
     * @param  string $field Current field
     * @param  array  $row   Contents of the current record.
     * @return string|null Return the supplied value if the record's file is an image. Return null otherwise
     *@see    Migration::writeTableToFile
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
    protected function users(): void
    {
        $user_Map = array(
            'AvatarID' => array('Column' => 'Photo', 'Filter' => array($this, 'getAvatarByID')),
        );
        $this->export(
            'User',
            "select
                    u.id as UserID,
                    u.username as UserID,
                    u.email as Email,
                    u.registration_ip as InsertIPAddress,
                    u.id as AvatarID,
                    u.password as Password,
                    from_unixtime(u.registered) as DateInserted,
                    from_unixtime(u.last_visit) as DateLastActive
                from :_users u
                where group_id <> 2",
            $user_Map
        );
    }

    /**
     */
    protected function roles(): void
    {
        $this->export(
            'Role',
            "select g_id as RoleID, g_title as Name from :_groups"
        );
        // UserRole
        $this->export(
            'UserRole',
            "select u.id as UserID, u.group_id as RoleID from :_users u"
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
                    'Plugin.Signatures.Sig' as Name,
                    signature as Value
                from :_users u
                where u.signature is not null"
        );
    }

    /**
     */
    protected function categories(): void
    {
        $this->export(
            'Category',
            "select
                    id as CategoryID,
                    forum_name as Name,
                    forum_desc as Description,
                    disp_position as Sort,
                    cat_id * 1000 as ParentCategoryID
                from :_forums f
                union
                select
                    id * 1000 as CategoryID,
                    cat_name as Name,
                    '' as Description,
                    disp_position as Sort,
                    NULL as ParentCategoryID
                from :_categories"
        );
    }

    /**
     */
    protected function discussions(): void
    {
        $this->export(
            'Discussion',
            "select
                    t.id as DiscussionID,
                    from_unixtime(p.posted) as DateInserted,
                    p.poster_id as InsertUserID,
                    p.poster_ip as InsertIPAddress,
                    p.message as Body,
                    t.closed as Closed,
                    t.sticky as Announce,
                    t.forum_id as CategoryID,
                    t.subject as Name,
                    from_unixtime(p.edited) as DateUpdated,
                    u.id as UpdateUserID,
                    'BBCode' as Format
                from :_topics t
                left join :_posts p on t.first_post_id = p.id
                left join :_users u on u.username = p.edited_by"
        );
    }

    /**
     */
    protected function comments(): void
    {
        $this->export(
            'Comment',
            "select
                    p.*,
                    p.id as CommentID,
                    p.poster_id as InsertUserID,
                    p.poster_ip as InsertIPAddress,
                    p.message as Body,
                    'BBCode' as Format,
                    from_unixtime(p.posted) as DateInserted,
                    from_unixtime(p.edited) as DateUpdated,
                    u.id as UpdateUserID
                from :_topics t
                join :_posts p on t.id = p.topic_id
                left join :_users u on u.username = p.edited_by
                where p.id <> t.first_post_id;"
        );
    }

    /**
     */
    protected function tags(): void
    {
        if ($this->hasInputSchema('tags')) {
            $this->export(
                'Tag',
                "select id as TagID, tag as Name from :_tags"
            );
            $this->export(
                'TagDiscussion',
                "select topic_id as DiscussionID, tag_id as TagID from :_topic_tags"
            );
        }
    }

    /**
     */
    protected function attachments(): void
    {
        if ($this->hasInputSchema('attach_files')) {
            $media_Map = array(
                'owner_id' => 'InsertUserID',
                'thumb_path' => array('Column' => 'ThumbPath', 'Filter' => array($this, 'filterThumbnailData')),
                'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
            );
            $this->export(
                'Media',
                "select f.*,
                        f.id as MediaID,
                        f.filename as Name,
                        f.size as Size,
                        f.type as Type,
                        f.ownder_id as InsertUserID,
                        concat({$this->cdn}, 'FileUpload/', f.file_path) as Path,
                        concat({$this->cdn}, 'FileUpload/', f.file_path) as thumb_path,
                        128 as thumb_width,
                        from_unixtime(f.uploaded_at) as DateInserted,
                        case when f.post_id is null then 'Discussion' else 'Comment' end as ForeignTable,
                        coalesce(f.post_id, f.topic_id) as ForeignID
                    from :_attach_files f",
                $media_Map
            );
        }
    }
}
