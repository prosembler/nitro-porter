<?php

/**
 * ASP Playground exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class AspPlayground extends Source
{
    public const SUPPORTED = [
        'name' => 'ASP Playground',
        'defaultTablePrefix' => 'pgd_',
        'charsetTable' => 'Threads',
        'features' => [
            'Users' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Bookmarks' => 1,
            'Polls' => 0, // Challenges noted inline below.
            'PrivateMessages' => 0, // Don't appear to be threaded in a rational way (see table PMsg).
        ]
    ];

    protected const FLAGS = [
        'hasDiscussionBody' => false,
    ];

    /**
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->signatures($port);

        $this->categories($port);

        $this->discussions($port);
        $this->comments($port);
        $this->bookmarks($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $map = [
            'Mem' => 'UserID',
            'Login' => 'Name',
            'Email' => 'Email',
            'Userpass' => 'Password',
            'totalPosts' => 'CountComments',
            'banned' => 'Banned',
            'dateSignUp' => 'DateInserted',
            'lastLogin' => 'DateLastActive',
            'location' => 'Location',
        ];
        $port->export(
            'User',
            "select m.*, 'Text' as HashMethod
                from :_Members m",
            $map
        );
    }

    /**
     * @param Migration $port
     */
    protected function signatures(Migration $port): void
    {
        $port->export(
            'UserMeta',
            "select Mem, 'Plugin.Signatures.Sig' as `Name`, signature as `Value`
            from :_Members
            where signature <> ''

            union all

            select Mem, 'Plugin.Signatures.Format' as `Name`, 'BBCode' as `Value`
            from :_Members
            where signature <> ''"
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $map = [
            'ForumID' => 'CategoryID',
            'ForumTitle' => 'Name',
            'ForumDesc' => 'Description',
            'Sort' => 'Sort',
            'lastModTime' => 'DateUpdated',
            'Total' => 'CountComments',
            'Topics' => 'CountDiscussions',
            'parent' => 'ParentCategoryID',
        ];
        $port->export(
            'Category',
            "select f.*
                from :_Forums f
                where linkTarget != 1", // External link categories have linkTarget==1
            $map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $map = [
            'messageID' => 'DiscussionID',
            'ForumID' => 'CategoryID',
            'mem' => 'InsertUserID',
            'dateCreated' => 'DateInserted',
            'Subject' => 'Name',
            'hits' => 'CountViews',
            'lastupdate' => 'DateLastComment',
        ];
        $port->export(
            'Discussion',
            "select t.*, m.Body
                from :_Threads t
                left join :_Messages m on m.messageID = t.messageID",
            $map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $map = [
            'messageID' => 'CommentID',
            'threadID' => 'DiscussionID',
            'parent' => 'ForeignID', // Preserve tree just in case.
            'Mem' => 'InsertUserID',
            'dateCreated' => 'DateInserted',
            //'Body' => 'Body',
        ];

        // Avoid adding OP redundantly.
        $skipOP = '';
        if ($this->getDiscussionBodyMode()) {
            $skipOP = "where parent != 0";
        }
        $port->export(
            'Comment',
            "select m.*, 'BBCode' as Format
                from :_Messages m
                $skipOP",
            $map
        );
    }

    /**
     * @param Migration $port
     */
    protected function bookmarks(Migration $port): void
    {
        $map = [
            'Mem' => 'UserID',
            'threadID' => 'DiscussionID',
        ];
        $port->export(
            'UserDiscussion',
            "select *, '1' as Bookmarked
                from :_Subscription
                where threadID is not null and isActive = 1",
            $map
        );
    }
}
