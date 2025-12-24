<?php

/**
 * Q2A exporter tool.
 *
 * @author  Eduardo Casarero
 */

namespace Porter\Source;

use Porter\Migration;
use Porter\Source;

class Q2a extends Source
{
    public const SUPPORTED = [
        'name' => 'Questions2Answers',
        'defaultTablePrefix' => 'qa_',
        'charsetTable' => 'posts',
        'features' => [
            'Users' => 1,
            'Passwords' => 0,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 0,
            'PrivateMessages' => 0,
        ]
    ];

    /** @var array[] List of required tables. */
    public array $sourceTables = array(
        'blobs' => array(),
        'categories' => array(),
        'posts' => array(),
        'users' => array(),
    );

    /**
     * Main export process.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->discussions($port);
        $this->comments($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $port->export(
            'User',
            "SELECT
                    u.userid as UserID,
                    u.handle as Name,
                    'Reset' as HashMethod,
                    u.email as Email,
                    u.created as DateInserted,
                    p.points as Points
                FROM :_users as u
                LEFT JOIN :_userpoints p USING(userid)
                WHERE u.userid IN (Select DISTINCT userid from :_posts)
                    AND (BIN(flags) & BIN(128) = 0) AND (BIN(flags) & BIN(2) = 0);"
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $port->export(
            'Role',
            "select 1 as RolesID, 'Member' as Name"
        );

        $port->export(
            'UserRole',
            "select
                    ur.userid as UserID,
                    1 as RoleID
                from :_users ur
                where (BIN(flags) & BIN(128) = 0) AND (BIN(flags) & BIN(2) = 0);"
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $port->export('Category', "select 1 as CategoryID, 'Legacy' as Name");
        $discussion_Map = array(
            'postid' => 'DiscussionID',
            'categoryid' => 'CategoryID',
            'userid' => 'InsertUserID',
            'Subject' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
        );
        $port->export(
            'Discussion',
            "select
                    'Question' as Type,
                    p.postid as DiscussionID,
                    1 as CategoryID,
                    p.userid as InsertUserID,
                    LEFT(p.title,99) as Name,
                    'HTML' as Format,
                    p.content as Body,
                    p.created as DateInserted,
                    1 as Closed,
                    'Accepted' as QnA
                from :_posts p
                WHERE parentid IS NULL
                    AND userid IS NOT NULL
                    AND type = 'Q';",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $port->export(
            'Comment',
            "select
                    p.postid as CommentID,
                    p.parentid as DiscussionID,
                    p.userid as InsertUserID,
                    p.content as Body,
                    'HTML' as Format,
                    p.created as DateInserted
                from :_posts p
                WHERE type = 'A'
                    AND userid IS NOT NULL ;"
        );
    }
}
