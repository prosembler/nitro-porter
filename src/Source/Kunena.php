<?php

/**
 * Joomla Kunena exporter tool
 *
 * @author  Todd Burry
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class Kunena extends Source
{
    public const SUPPORTED = [
        'name' => 'Joomla Kunena',
        'defaultTablePrefix' => 'jos_',
        'charsetTable' => 'kunena_messages',
        'passwordHashMethod' => 'joomla',
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
            'Signatures' => 0,
            'Attachments' => 1,
            'Bookmarks' => 1,
        ]
    ];

    /**
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->bookmarks($port);
        $this->attachments($port);
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     *
     * @param  string $value Current value
     * @param  string $field Current field
     * @param  array  $row   Contents of the current record.
     * @return string|null Return the supplied value if the record's file is an image. Return null otherwise
     * @see    Migration::writeTableToFile
     */
    public function filterThumbnailData($value, $field, $row): ?string
    {
        if (strpos(strtolower($row['filetype']), 'image/') === 0) {
            return $value;
        }
        return null;
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
            'id' => 'UserID',
            'name' => 'Name',
            'email' => 'Email',
            'registerDate' => 'DateInserted',
            'lastvisitDate' => 'DateLastActive',
            'password' => 'Password',
            'showemail' => 'ShowEmail',
            'birthdate' => 'DateOfBirth',
            'banned' => 'Banned',
            //         'DELETED'=>'Deleted',
            'admin' => array('Column' => 'Admin', 'Type' => 'tinyint(1)'),
            'Photo' => 'Photo'
        );
        $port->export(
            'User',
            "SELECT
                    u.*,
                    case when ku.avatar <> '' then concat('kunena/avatars/', ku.avatar) else null end as `Photo`,
                    case group_id when 'superadministrator' then 1 else 0 end as admin,
                    if(isnull(ku.banned), 0, 1) as banned,
                    ku.birthdate,
                    !ku.hideemail as showemail
                FROM :_users u
                left join :_kunena_users ku
                    on ku.userid = u.id",
            $user_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'rank_id' => 'RoleID',
            'rank_title' => 'Name',
        );
        $port->export('Role', "select * from :_kunena_ranks", $role_Map);

        // UserRole.
        $userRole_Map = array(
            'id' => 'UserID',
            'rank' => 'RoleID'
        );
        $port->export(
            'UserRole',
            "select * from :_users u",
            $userRole_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'id' => 'CategoryID',
            'parent' => 'ParentCategoryID',
            'name' => 'Name',
            'ordering' => 'Sort',
            'description' => 'Description',

        );
        $port->export(
            'Category',
            "select * from :_kunena_categories",
            $category_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'id' => 'DiscussionID',
            'catid' => 'CategoryID',
            'userid' => 'InsertUserID',
            'subject' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'time' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'ip' => 'InsertIPAddress',
            'locked' => 'Closed',
            'hits' => 'CountViews',
            'modified_by' => 'UpdateUserID',
            'modified_time' => array('Column' => 'DateUpdated', 'Filter' => 'timestampToDate'),
            'message' => 'Body',
            'Format' => 'Format'
        );
        $port->export(
            'Discussion',
            "select t.*,
                    txt.message,
                    'BBCode' as Format
                 from :_kunena_messages t
                 left join :_kunena_messages_text txt
                    on t.id = txt.mesid
                 where t.thread = t.id",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'id' => 'CommentID',
            'thread' => 'DiscussionID',
            'userid' => 'InsertUserID',
            'time' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'ip' => 'InsertIPAddress',
            'modified_by' => 'UpdateUserID',
            'modified_time' => array('Column' => 'DateUpdated', 'Filter' => 'timestampToDate'),
            'message' => 'Body',
            'Format' => 'Format'
        );
        $port->export(
            'Comment',
            "select t.*,
                    txt.message,
                    'BBCode' as Format
                 from :_kunena_messages t
                 left join :_kunena_messages_text txt
                    on t.id = txt.mesid
                 where t.thread <> t.id",
            $comment_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function bookmarks(Migration $port): void
    {
        $userDiscussion_Map = array(
            'thread' => 'DiscussionID',
            'userid' => 'UserID'
        );
        $port->export(
            'UserDiscussion',
            "select t.*, 1 as Bookmarked from :_kunena_user_topics t",
            $userDiscussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function attachments(Migration $port): void
    {
        $media_Map = array(
            'id' => 'MediaID',
            'mesid' => 'ForeignID',
            'userid' => 'InsertUserID',
            'size' => 'Size',
            'path2' => array('Column' => 'Path', 'Filter' => 'urlDecode'),
            'thumb_path' => array('Column' => 'ThumbPath', 'Filter' => array($this, 'filterThumbnailData')),
            'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
            'filetype' => 'Type',
            'filename' => array('Column' => 'Name', 'Filter' => 'urlDecode'),
            'time' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
        );
        $port->export(
            'Media',
            "select a.*,
                    concat(a.folder, '/', a.filename) as path2,
                    case when m.id = m.thread then 'discussion' else 'comment' end as ForeignTable,
                    m.time,
                    concat(a.folder, '/', a.filename) as thumb_path,
                    128 as thumb_width
                 from :_kunena_attachments a
                 join :_kunena_messages m
                    on m.id = a.mesid",
            $media_Map
        );
    }
}
