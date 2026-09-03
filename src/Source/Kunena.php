<?php

/**
 * Joomla Kunena exporter tool
 *
 * @author  Todd Burry
 */

namespace Porter\Source;

use Porter\Source;

class Kunena extends Source
{
    public const array INFO = [
        'name' => 'Joomla Kunena',
        'defaultTablePrefix' => 'jos_',
        'charsetTable' => 'kunena_messages',
        'passwordHashMethod' => 'joomla',
    ];

    protected function users(): void
    {
        $map = [
            'id' => 'UserID',
            'name' => 'Name',
            'email' => 'Email',
            'registerDate' => 'DateInserted',
            'lastvisitDate' => 'DateLastActive',
            'password' => 'Password',
            'showemail' => 'ShowEmail',
            'birthdate' => 'DateOfBirth',
            'banned' => 'Banned',
            'admin' => 'Admin',
        ];
        $this->export(
            'User',
            "select u.*, ku.birthdate, !ku.hideemail as showemail, if(isnull(ku.banned), 0, 1) as banned,
                    case when ku.avatar <> '' then concat('kunena/avatars/', ku.avatar) else null end as `Photo`,
                    case group_id when 'superadministrator' then 1 else 0 end as admin
                from :_users u
                left join :_kunena_users ku on ku.userid = u.id",
            $map
        );
    }

    protected function roles(): void
    {
        $role_Map = ['rank_id' => 'RoleID', 'rank_title' => 'Name',];
        $this->export('Role', "select * from :_kunena_ranks", $role_Map);
        // UserRole.
        $userRole_Map = ['id' => 'UserID', 'rank' => 'RoleID'];
        $this->export('UserRole', "select * from :_users u", $userRole_Map);
    }

    protected function categories(): void
    {
        $category_Map = [
            'id' => 'CategoryID',
            'parent' => 'ParentCategoryID',
            'name' => 'Name',
            'ordering' => 'Sort',
            'description' => 'Description',
        ];
        $this->export('Category', "select * from :_kunena_categories", $category_Map);
    }

    protected function discussions(): void
    {
        $map = [
            'id' => 'DiscussionID',
            'catid' => 'CategoryID',
            'userid' => 'InsertUserID',
            'subject' => 'Name',
            'time' => 'DateInserted',
            'ip' => 'InsertIPAddress',
            'locked' => 'Closed',
            'hits' => 'CountViews',
            'modified_by' => 'UpdateUserID',
            'modified_time' => 'DateUpdated',
            'message' => 'Body',
            'Format=BBCode',
        ];
        $filters = [
            'subject' => \Porter\Filter\DecodeHtml::class,
            'time' => \Porter\Filter\UnixtimeToDate::class,
            'modified_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Discussion',
            "select t.*, txt.message
                 from :_kunena_messages t
                 left join :_kunena_messages_text txt on t.id = txt.mesid
                 where t.thread = t.id",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $map = [
            'id' => 'CommentID',
            'thread' => 'DiscussionID',
            'userid' => 'InsertUserID',
            'time' => 'DateInserted',
            'ip' => 'InsertIPAddress',
            'modified_by' => 'UpdateUserID',
            'modified_time' => 'DateUpdated',
            'message' => 'Body',
            'Format=BBCode',
        ];
        $filters = [
            'time' => \Porter\Filter\UnixtimeToDate::class,
            'modified_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Comment',
            "select t.*, txt.message
                 from :_kunena_messages t
                 left join :_kunena_messages_text txt on t.id = txt.mesid
                 where t.thread <> t.id",
            $map,
            $filters
        );
    }

    protected function bookmarks(): void
    {
        $map = [
            'thread' => 'DiscussionID',
            'userid' => 'UserID',
            'Bookmarked=1',
        ];
        $this->export('UserDiscussion', "select t.* from :_kunena_user_topics t", $map);
    }

    protected function attachments(): void
    {
        $map = [
            'id' => 'MediaID',
            'mesid' => 'ForeignID',
            'userid' => 'InsertUserID',
            'size' => 'Size',
            'path2' => 'Path',
            'thumb_path' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
            'filetype' => 'Type',
            'filename' => 'Name',
            'time' => 'DateInserted',
        ];
        $filters = [
            'path2' => \Porter\Filter\DecodeUrl::class,
            'filename' => \Porter\Filter\DecodeUrl::class,
            'time' => \Porter\Filter\UnixtimeToDate::class,
            'thumb_path' => \Porter\Filter\NullIfNotImage::class,
            'thumb_width' => \Porter\Filter\NullIfNotImage::class,
        ];
        $this->export(
            'Media',
            "select a.*, m.time, filetype as Mime,
                    concat(a.folder, '/', a.filename) as path2,
                    case when m.id = m.thread then 'discussion' else 'comment' end as ForeignTable,
                    concat(a.folder, '/', a.filename) as thumb_path,
                    128 as thumb_width
                 from :_kunena_attachments a
                 join :_kunena_messages m on m.id = a.mesid",
            $map,
            $filters
        );
    }
}
