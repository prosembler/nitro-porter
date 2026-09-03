<?php

/**
 * Q2A exporter tool.
 *
 * @author  Eduardo Casarero
 */

namespace Porter\Source;

use Porter\Source;

class Q2a extends Source
{
    public const array INFO = [
        'name' => 'Questions2Answers',
        'defaultTablePrefix' => 'qa_',
        'charsetTable' => 'posts',
    ];

    /** @var array[] List of required tables. */
    public array $sourceTables = [
        'posts' => [],
        'users' => [],
    ];

    protected function users(): void
    {
        $map = [
            'userid' => 'UserID',
            'handle' => 'Name',
            'email' => 'Email',
            'created' => 'DateInserted',
            'points' => 'Points',
            'HashMethod=Reset'
        ];
        $query = "select u.userid, u.handle, u.email, u.created, p.points
                from :_users as u
                left join :_userpoints p using(userid)
                where u.userid in (select distinct userid from :_posts)
                    and (BIN(flags) & BIN(128) = 0) AND (BIN(flags) & BIN(2) = 0)";
        $this->export('User', $query, $map);
    }

    protected function roles(): void
    {
        // Create a new role.
        $this->export('Role', "select 1 as RolesID, 'Member' as Name");
        $this->export(
            'UserRole',
            "select ur.userid as UserID, 1 as RoleID
                from :_users ur
                where (BIN(flags) & BIN(128) = 0) AND (BIN(flags) & BIN(2) = 0);"
        );
    }

    protected function discussions(): void
    {
        $this->export('Category', "select 1 as CategoryID, 'Legacy' as Name");
        $map = [
            'postid' => 'DiscussionID',
            'categoryid' => 'CategoryID',
            'userid' => 'InsertUserID',
            'content' => 'Body',
            'created' => 'DateInserted',
            'Type=Question',
            'CategoryID=1',
            'Format=Html',
            'Closed=1',
            'QnA=Accepted',
        ];
        $filters = [
            'Subject' => \Porter\Filter\DecodeHtml::class,
        ];
        $query = "select p.postid, p.userid, p.content,  p.created, LEFT(p.title,99) as Name
                from :_posts p
                where parentid is null and userid IS NOT null and type = 'Q'";
        $this->export('Discussion', $query, $map, $filters);
    }

    protected function comments(): void
    {
        $map = [
            'postid' => 'CommentID',
            'parentid' => 'DiscussionID',
            'userid' => 'InsertUserID',
            'content' => 'Body',
            'created' => 'DateInserted',
            'Format=Html',
        ];
        $query = "select p.postid, p.parentid, p.userid, p.content, p.created
                from :_posts p
                where type = 'A' and userid IS NOT NULL";
        $this->export('Comment', $query, $map);
    }
}
