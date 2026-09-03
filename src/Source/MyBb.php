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
    public const array INFO = [
        'name' => 'MyBB',
        'defaultTablePrefix' => 'mybb_',
        'charsetTable' => 'posts',
    ];

    public array $sourceTables = [
        'forums' => [],
        'posts' => [],
        'threads' => [],
        'users' => [],
    ];

    protected function users(): void
    {
        $map = [
            'uid' => 'UserID',
            'username' => 'Name',
            'avatar' => 'Photo',
            'regdate' => 'DateInserted',
            'lastactive' => 'DateLastActive',
            'email' => 'Email',
            'HashMethod=mybb',
        ];
        $filters = [
            'username' => \Porter\Filter\DecodeHtml::class,
            'regdate' => \Porter\Filter\UnixtimeToDate::class,
            'lastactive' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export('User', "select u.*, concat(password, salt) as Password from :_users u", $map, $filters);
    }

    protected function roles(): void
    {
        $map = [
            'gid' => 'RoleID',
            'title' => 'Name',
            'description' => 'Description',
        ];
        $this->export('Role', "select * from :_usergroups", $map);

        // User Role.
        $map = [
            'uid' => 'UserID',
            'usergroup' => 'RoleID',
        ];
        $this->export('UserRole', "select uid, usergroup from :_users", $map);
    }

    protected function categories(): void
    {
        $map = [
            'fid' => 'CategoryID',
            'pid' => 'ParentCategoryID',
            'disporder' => 'Sort',
            'name' => 'Name',
            'description' => 'Description',
        ];
        $this->export('Category', "select * from :_forums", $map);
    }

    protected function discussions(): void
    {
        $map = [
            'tid' => 'DiscussionID',
            'fid' => 'CategoryID',
            'uid' => 'InsertUserID',
            'subject' => 'Name',
            'views' => 'CountViews',
            'replies' => 'CountComments',
            'dateline' => 'DateInserted',
            'Format=BBCode',
        ];
        $filters = [
            'subject' => \Porter\Filter\DecodeHtml::class,
            'dateline' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Discussion',
            "select * from :_threads",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $comment_Map = [
            'pid' => 'CommentID',
            'tid' => 'DiscussionID',
            'uid' => 'InsertUserID',
            'dateline' => 'DateInserted',
            'message' => 'Body',
            'Format=BBCode',
        ];
        $filters = [
            'dateline' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export('Comment', "select * from :_posts", $comment_Map, $filters);
    }

    protected function attachments(): void
    {
        $map = [
            'aid' => 'MediaID',
            'pid' => 'ForeignID',
            'uid' => 'InsertUserId',
            'filesize' => 'Size',
            'filename' => 'Name',
            'height' => 'ImageHeight',
            'width' => 'ImageWidth',
            'filetype' => 'Type',
            'thumb_width' => 'ThumbWidth',
            'ForeignTable=Comment',
            'ThumbWidth=600',
        ];
        $filters = [
            'dateline' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Media',
            "select *,
                    concat('attachments/', thumbnail) as ThumbPath,
                    concat('attachments/', attachname) as Path
                from :_attachments where pid > 0",
            $map,
            $filters
        );
    }

    protected function bookmarks(): void
    {
        $map = [
            'tid' => 'DiscussionID',
            'uid' => 'UserID',
            'Bookmarked=1',
        ];
        $this->export('UserDiscussion', "select * from :_threadsubscriptions", $map);
    }
}
