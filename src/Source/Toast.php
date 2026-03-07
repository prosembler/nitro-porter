<?php

/**
 * Toast (.NET) exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

class Toast extends Source
{
    public const SUPPORTED = [
        'name' => 'Toast',
        'defaultTablePrefix' => 'tstdb_',
        'charsetTable' => 'Post',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 0,
            'PrivateMessages' => 0,
            'Signatures' => 1,
        ]
    ];

    /**
     * Main export method.
     *
     */
    public function run(): void
    {
        $this->users();
        $this->roles();
        $this->signatures();
        $this->categories();
        $this->discussions();
        $this->comments();
    }

    /**
     */
    protected function users(): void
    {
        $user_Map = array(
            'ID' => 'UserID',
            'Username' => 'Name',
            'Email' => 'Email',
            'LastLoginDate' => array('Column' => 'DateLastActive', 'Type' => 'datetime'),
            'IP' => 'LastIPAddress'
        );
        $this->export(
            'User',
            "select *, NOW() as DateInserted from :_Member u",
            $user_Map
        );
    }

    /**
     */
    protected function roles(): void
    {
        // Determine safe RoleID to use for non-existant Member role
        $lastRoleID = 1001;
        $lastRoleResult = $this->query("select max(ID) as LastID from :_Group");
        if ($lastRole = $lastRoleResult->nextResultRow()) {
            $lastRoleID = $lastRole['LastID'] + 1;
        }

        // Add default Member role.
        $role_Map = array(
            'ID' => 'RoleID',
            'Name' => 'Name'
        );
        $this->export(
            'Role',
            " select ID, Name from :_Group
                union all
                select $lastRoleID as ID, 'Member' as Name from :_Group;",
            $role_Map
        );

        // UserRole.
        // Users without roles get put into new Member role.
        $userRole_Map = array(
            'MemberID' => 'UserID',
            'GroupID' => 'RoleID'
        );
        $this->export(
            'UserRole',
            " select GroupID, MemberID from :_MemberGroupLink
                 union all
                 select
                    $lastRoleID as GroupID,
                    m.ID as MemberID
                 from :_Member m
                 left join :_MemberGroupLink l
                    on l.MemberID = m.ID
                 where l.GroupID is null",
            $userRole_Map
        );
    }

    /**
     */
    protected function signatures(): void
    {
        $this->export(
            'UserMeta',
            " select
                    ID as UserID,
                    'Plugin.Signatures.Sig' as `Name`,
                    Signature as `Value`
                 from :_Member
                 where Signature <> ''
                 union all
                 select
                    ID as UserID,
                    'Plugin.Signatures.Format' as `Name`,
                    'BBCode' as `Value`
                 from :_Member
                 where Signature <> '';"
        );
    }

    /**
     */
    protected function categories(): void
    {
        $category_Map = array(
            'ID' => 'CategoryID',
            'CategoryID' => 'ParentCategoryID',
            'ForumName' => 'Name',
            'Description' => 'Description'
        );
        $this->export(
            'Category',
            "select
                    f.ID,
                    f.CategoryID * 1000 as CategoryID,
                    f.ForumName,
                    f.Description
                from :_Forum f
                union all
                select
                    c.ID * 1000 as ID,
                    -1 as CategoryID,
                    c.Name as ForumName,
                    null as Description
                from :_Category c;",
            $category_Map
        );
    }

    /**
     */
    protected function discussions(): void
    {
        $discussion_Map = array(
            'ID' => 'DiscussionID',
            'ForumID' => 'CategoryID',
            'MemberID' => 'InsertUserID',
            'PostDate' => 'DateInserted',
            'ModifyDate' => 'DateUpdated',
            'LastPostDate' => 'DateLastComment',
            'Subject' => 'Name',
            'Message' => 'Body',
            'Hits' => 'CountViews',
            'ReplyCount' => 'CountComments'
        );
        $this->export(
            'Discussion',
            "select p.*,
            'Html' as Format
                from :_Post p
                where p.Topic = 1
                    and p.Deleted = 0;",
            $discussion_Map
        );
    }

    /**
     */
    protected function comments(): void
    {
        $comment_Map = array(
            'ID' => 'CommentID',
            'TopicID' => 'DiscussionID',
            'MemberID' => 'InsertUserID',
            'PostDate' => 'DateInserted',
            'ModifyDate' => 'DateUpdated',
            'Message' => 'Body'
        );
        $this->export(
            'Comment',
            "select *,
                    'Html' as Format
                from :_Post p
                where Topic = 0 and Deleted = 0;",
            $comment_Map
        );
    }
}
