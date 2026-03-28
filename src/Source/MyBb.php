<?php

/**
 * MyBB exporter tool.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 *
 * @see functions.commandline.php for command line usage.
 */

namespace Porter\Source;

use Porter\Source;

class MyBb extends Source
{
    public const SUPPORTED = [
        'name' => 'MyBB',
        'defaultTablePrefix' => 'mybb_',
        'charsetTable' => 'posts',
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
     * You can use this to require certain tables and columns be present.
     *
     * @var array Required tables => columns
     */
    public array $sourceTables = array(
        'forums' => array(),
        'posts' => array(),
        'threads' => array(),
        'users' => array(),
    );

    /**
     * Main export process.
     *
     */
    public function run(): void
    {
        $this->users();
        $this->roles();
        $this->categories();
        $this->discussions();
        $this->comments();
        $this->attachments();
        $this->bookmarks();
    }

    /**
     */
    protected function users(): void
    {
        $user_Map = array(
            'uid' => 'UserID',
            'username' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'avatar' => 'Photo',
            'regdate2' => 'DateInserted',
            'regdate3' => 'DateFirstVisit',
            'email' => 'Email',
        );
        $this->export(
            'User',
            "select u.*,
                FROM_UNIXTIME(regdate) as regdate2,
                FROM_UNIXTIME(regdate) as regdate3,
                FROM_UNIXTIME(lastactive) as DateLastActive,
                concat(password, salt) as Password,
                'mybb' as HashMethod
             from :_users u",
            $user_Map
        );
    }

    /**
     */
    protected function roles(): void
    {
        $role_Map = array(
            'gid' => 'RoleID',
            'title' => 'Name',
            'description' => 'Description',
        );
        $this->export(
            'Role',
            "select * from :_usergroups",
            $role_Map
        );

        // User Role.
        $userRole_Map = array(
            'uid' => 'UserID',
            'usergroup' => 'RoleID',
        );
        $this->export(
            'UserRole',
            "select u.uid, u.usergroup from :_users u",
            $userRole_Map
        );
    }

    /**
     */
    protected function categories(): void
    {
        $category_Map = array(
            'fid' => 'CategoryID',
            'pid' => 'ParentCategoryID',
            'disporder' => 'Sort',
            'name' => 'Name',
            'description' => 'Description',
        );
        $this->export(
            'Category',
            "select * from :_forums f",
            $category_Map
        );
    }

    /**
     */
    protected function discussions(): void
    {
        $discussion_Map = array(
            'tid' => 'DiscussionID',
            'fid' => 'CategoryID',
            'uid' => 'InsertUserID',
            'subject' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'views' => 'CountViews',
            'replies' => 'CountComments',
        );
        $this->export(
            'Discussion',
            "select *,
                    FROM_UNIXTIME(dateline) as DateInserted,
                    'BBCode' as Format
                from :_threads t",
            $discussion_Map
        );
    }

    /**
     */
    protected function comments(): void
    {
        $comment_Map = array(
            'pid' => 'CommentID',
            'tid' => 'DiscussionID',
            'uid' => 'InsertUserID',
            'message' => array('Column' => 'Body'),
        );
        $this->export(
            'Comment',
            "select p.*,
                    FROM_UNIXTIME(dateline) as DateInserted,
                    'BBCode' as Format
                from :_posts p",
            $comment_Map
        );
    }

    /**
     */
    protected function attachments(): void
    {
        $media_Map = array(
            'aid' => 'MediaID',
            'pid' => 'ForeignID',
            'uid' => 'InsertUserId',
            'filesize' => 'Size',
            'filename' => 'Name',
            'height' => 'ImageHeight',
            'width' => 'ImageWidth',
            'filetype' => 'Type',
            'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
        );
        $this->export(
            'Media',
            "select a.*,
                    600 as thumb_width,
                    concat('attachments/', a.thumbnail) as ThumbPath,
                    concat('attachments/', a.attachname) as Path,
                    'Comment' as ForeignTable
                from :_attachments a
                where a.pid > 0",
            $media_Map
        );
    }

    /**
     */
    protected function bookmarks(): void
    {
        $userDiscussion_Map = array(
            'tid' => 'DiscussionID',
            'uid' => 'UserID',
        );
        $this->export(
            'UserDiscussion',
            "select *,
                    1 as Bookmarked
                from :_threadsubscriptions t",
            $userDiscussion_Map
        );
    }
}
