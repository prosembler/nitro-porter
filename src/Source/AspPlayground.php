<?php

/**
 * ASP Playground exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

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
     */
    public function run(): void
    {
        $this->users();
        $this->signatures();

        $this->categories();

        $this->discussions();
        $this->comments();
        $this->bookmarks();
    }

    /**
     */
    protected function users(): void
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
        $this->export(
            'User',
            "select m.*, 'Text' as HashMethod
                from :_Members m",
            $map
        );
    }

    /**
     */
    protected function signatures(): void
    {
        $this->export(
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
     */
    protected function categories(): void
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
        $this->export(
            'Category',
            "select f.*
                from :_Forums f
                where linkTarget != 1", // External link categories have linkTarget==1
            $map
        );
    }

    /**
     */
    protected function discussions(): void
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
        $this->export(
            'Discussion',
            "select t.*, m.Body
                from :_Threads t
                left join :_Messages m on m.messageID = t.messageID",
            $map
        );
    }

    /**
     */
    protected function comments(): void
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
        $this->export(
            'Comment',
            "select m.*, 'BBCode' as Format
                from :_Messages m
                $skipOP",
            $map
        );
    }

    /**
     */
    protected function bookmarks(): void
    {
        $map = [
            'Mem' => 'UserID',
            'threadID' => 'DiscussionID',
        ];
        $this->export(
            'UserDiscussion',
            "select *, '1' as Bookmarked
                from :_Subscription
                where threadID is not null and isActive = 1",
            $map
        );
    }
}
