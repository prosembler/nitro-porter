<?php

/**
 * Vanilla 1 exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

class Vanilla1 extends Source
{
    public const array INFO = [
        'name' => 'Vanilla 1',
        'defaultTablePrefix' => 'LUM_',
        'charsetTable' => 'Comment',
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = [
        'User' => ['UserID', 'Name', 'Password', 'Email', 'CountComments'],
        'Role' => ['RoleID', 'Name', 'Description'],
        'Category' => ['CategoryID', 'Name', 'Description'],
        'Discussion' => [
            'DiscussionID',
            'Name',
            'CategoryID',
            'DateCreated',
            'AuthUserID',
            'DateLastActive',
            'Closed',
            'Sticky',
            'CountComments',
            'Sink',
            'LastUserID'
        ],
        'Comment' => [
            'CommentID',
            'DiscussionID',
            'AuthUserID',
            'DateCreated',
            'EditUserID',
            'DateEdited',
            'Body',
            'Deleted'
        ]
    ];

    protected function users(): void
    {
        $map = [
            'UserID' => 'UserID',
            'Name' => 'Name',
            'Password' => 'Password',
            'Email' => 'Email',
            'Icon' => 'Photo',
            'CountComments' => 'CountComments',
            'Discovery' => 'DiscoveryText'
        ];
        $this->export('User', "SELECT * FROM :_User", $map);
    }

    protected function roles(): void
    {
        // Since the zero role is a valid role in Vanilla 1 then we'll have to reassign it.
        $r = $this->query('select max(RoleID) as RoleID from :_Role');
        $zeroRoleID = 0;
        if (is_object($r)) {
            while ($row = $r->nextResultRow()) {
                $zeroRoleID = $row['RoleID'];
            }
        }
        $zeroRoleID++;

        $map = [];
        $this->export(
            'Role',
            "select RoleID, Name, Description
                from :_Role
                union all
                select $zeroRoleID, 'Applicant', 'Created by Nitro Porter'",
            $map
        );

        // UserRoles
        $this->export(
            'UserRole',
            "select UserID, case RoleID when 0 then $zeroRoleID else RoleID end as RoleID from :_User",
            $map
        );
    }

    protected function categories(): void
    {
        $map = [];
        $this->export('Category', "select CategoryID, Name, Description from :_Category", $map);
    }

    protected function discussions(): void
    {
        $discussion_Map = [
            'DiscussionID' => 'DiscussionID',
            'Name' => 'Name',
            'CategoryID' => 'CategoryID',
            'DateCreated' => 'DateInserted',
            'DateCreated2' => 'DateUpdated',
            'AuthUserID' => 'InsertUserID',
            'DateLastActive' => 'DateLastComment',
            'AuthUserID2' => 'UpdateUserID',
            'Closed' => 'Closed',
            'Sticky' => 'Announce',
            'CountComments' => 'CountComments',
            'Sink' => 'Sink',
            'LastUserID' => 'LastCommentUserID',
            'FormatType' => 'Format',
        ];
        $this->export(
            'Discussion',
            "SELECT d.*, d.DateCreated as DateCreated2, d.AuthUserID as AuthUserID2, c.Body
                FROM :_Discussion d
                LEFT JOIN :_Comment c ON d.FirstCommentID = c.CommentID
                WHERE coalesce(d.WhisperUserID, 0) = 0 and d.Active = 1",
            $discussion_Map
        );
    }

    protected function bookmarks(): void
    {
        $this->export(
            'UserDiscussion',
            "SELECT w.UserID, w.DiscussionID, w.CountComments, w.LastViewed as DateLastViewed,
                    case when b.UserID is not null then 1 else 0 end AS Bookmarked
                FROM :_UserDiscussionWatch w
                LEFT JOIN :_UserBookmark b ON w.DiscussionID = b.DiscussionID AND w.UserID = b.UserID"
        );
    }

    protected function comments(): void
    {
        $comment_Map = [
            'AuthUserID' => 'InsertUserID',
            'DateCreated' => 'DateInserted',
            'EditUserID' => 'UpdateUserID',
            'DateEdited' => 'DateUpdated',
            'FormatType' => 'Format'
        ];
        $this->export(
            'Comment',
            "SELECT c.*
                 FROM :_Comment c
                 JOIN :_Discussion d ON c.DiscussionID = d.DiscussionID
                 WHERE d.FirstCommentID <> c.CommentID AND c.Deleted = '0'
                    AND coalesce(d.WhisperUserID, 0) = 0 AND coalesce(c.WhisperUserID, 0) = 0",
            $comment_Map
        );
    }

    protected function conversations(): void
    {
        // These mapping tables are used to group comments that a) are in the same discussion
        // and b) are from and to the same users.
        $this->dbInput()->unprepared("drop table if exists z_pmto");
        $this->dbInput()->unprepared("create table z_pmto (CommentID int, UserID int,
                primary key(CommentID, UserID) )");
        $this->dbInput()->unprepared("insert ignore z_pmto (CommentID, UserID)
                select distinct CommentID, AuthUserID
                from :_Comment
                where coalesce(WhisperUserID, 0) <> 0");
        $this->dbInput()->unprepared("insert ignore z_pmto (CommentID, UserID)
                select distinct CommentID, WhisperUserID
                from :_Comment
                where coalesce(WhisperUserID, 0) <> 0");
        $this->dbInput()->unprepared("insert ignore z_pmto (CommentID, UserID)
                select distinct c.CommentID, d.AuthUserID
                from :_Discussion d
                join :_Comment c on c.DiscussionID = d.DiscussionID
                where coalesce(d.WhisperUserID, 0) <> 0");
        $this->dbInput()->unprepared("insert ignore z_pmto (CommentID, UserID)
                select distinct c.CommentID, d.WhisperUserID
                from :_Discussion d
                join :_Comment c on c.DiscussionID = d.DiscussionID
                where coalesce(d.WhisperUserID, 0) <> 0");
        $this->dbInput()->unprepared("insert ignore z_pmto (CommentID, UserID)
                select distinct c.CommentID, c.AuthUserID
                from :_Discussion d
                join :_Comment c on c.DiscussionID = d.DiscussionID
                where coalesce(d.WhisperUserID, 0) <> 0");

        $this->dbInput()->unprepared("drop table if exists z_pmto2");
        $this->dbInput()->unprepared("create table z_pmto2 (
              CommentID int,
              UserIDs varchar(250),
              primary key (CommentID) )");
        $this->dbInput()->unprepared("insert z_pmto2 (CommentID, UserIDs)
                select CommentID, group_concat(UserID order by UserID)
                from z_pmto
                group by CommentID");

        $this->dbInput()->unprepared("drop table if exists z_pm");
        $this->dbInput()->unprepared("create table z_pm (
              CommentID int,
              DiscussionID int,
              UserIDs varchar(250),
              GroupID int )");
        $this->dbInput()->unprepared("insert ignore z_pm (CommentID, DiscussionID)
                select CommentID, DiscussionID
                from :_Comment
                where coalesce(WhisperUserID, 0) <> 0");
        $this->dbInput()->unprepared("insert ignore z_pm (CommentID, DiscussionID)
                select c.CommentID, c.DiscussionID
                from :_Discussion d
                join :_Comment c on c.DiscussionID = d.DiscussionID
                where coalesce(d.WhisperUserID, 0) <> 0");
        $this->dbInput()->unprepared("update z_pm pm
                join z_pmto2 t on t.CommentID = pm.CommentID
                set pm.UserIDs = t.UserIDs");

        $this->dbInput()->unprepared("drop table if exists z_pmgroup");
        $this->dbInput()->unprepared("create table z_pmgroup (
                GroupID int,
                DiscussionID int,
                UserIDs varchar(250) )");
        $this->dbInput()->unprepared("insert z_pmgroup (GroupID, DiscussionID, UserIDs)
                select min(pm.CommentID), pm.DiscussionID, t2.UserIDs
                from z_pm pm
                join z_pmto2 t2 on pm.CommentID = t2.CommentID
                group by pm.DiscussionID, t2.UserIDs");
        $this->dbInput()->unprepared("create index z_idx_pmgroup on z_pmgroup (DiscussionID, UserIDs)");
        $this->dbInput()->unprepared("create index z_idx_pmgroup2 on z_pmgroup (GroupID)");

        $this->dbInput()->unprepared("update z_pm pm
                join z_pmgroup g on pm.DiscussionID = g.DiscussionID and pm.UserIDs = g.UserIDs
                set pm.GroupID = g.GroupID");

        $conversation_Map = [
            'AuthUserID' => 'InsertUserID',
            'DateCreated' => 'DateInserted',
            'CommentID' => 'ConversationID',
            'Name' => 'Subject',
        ];
        $this->export(
            'Conversation',
            "select c.*, d.Name
                from :_Comment c
                join :_Discussion d on d.DiscussionID = c.DiscussionID
                join z_pmgroup g on g.GroupID = c.CommentID;",
            $conversation_Map
        );

        // ConversationMessage.
        $conversationMessage_Map = [
            'CommentID' => 'MessageID',
            'GroupID' => 'ConversationID',
            'FormatType' => 'Format',
            'AuthUserID' => 'InsertUserID',
            'DateCreated' => 'DateInserted'
        ];
        $this->export(
            'ConversationMessage',
            "select c.*, pm.GroupID
                from z_pm pm
                join :_Comment c on pm.CommentID = c.CommentID",
            $conversationMessage_Map
        );

        // UserConversation
        $userConversation_Map = [
            'GroupID' => 'ConversationID'
        ];
        $this->export(
            'UserConversation',
            "select distinct pm.GroupID, t.UserID
                from z_pmto t
                join z_pm pm on pm.CommentID = t.CommentID",
            $userConversation_Map
        );

        $this->dbInput()->unprepared("drop table z_pmto");
        $this->dbInput()->unprepared("drop table z_pmto2");
        $this->dbInput()->unprepared("drop table z_pm");
        $this->dbInput()->unprepared("drop table z_pmgroup");
    }

    protected function attachments(): void
    {
        if (!$this->hasInputSchema('Attachment')) {
            return;
        }
        $map = [
            'AttachmentID' => 'MediaID',
            'MimeType' => 'Type',
            'UserID' => 'InsertUserID',
            'DateCreated' => 'DateInserted',
            'CommentID' => 'ForeignID',
            'ForeignTable=comment',
        ];
        $filters = [
            'Path' => \Porter\Filter\RemoveVanilla1Folder::class,
        ];
        $this->export('Media', "select a.* from :_Attachment a", $map, $filters);
    }
}
