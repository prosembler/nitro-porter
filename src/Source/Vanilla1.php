<?php

/**
 * Vanilla 1 exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class Vanilla1 extends Source
{
    public const SUPPORTED = [
        'name' => 'Vanilla 1',
        'defaultTablePrefix' => 'LUM_',
        'charsetTable' => 'Comment',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 0,
            'PrivateMessages' => 1,
            'Signatures' => 0,
            'Attachments' => 1,
            'Bookmarks' => 1,
        ]
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = array(
        'User' => array('UserID', 'Name', 'Password', 'Email', 'CountComments'),
        'Role' => array('RoleID', 'Name', 'Description'),
        'Category' => array('CategoryID', 'Name', 'Description'),
        'Discussion' => array(
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
        ),
        'Comment' => array(
            'CommentID',
            'DiscussionID',
            'AuthUserID',
            'DateCreated',
            'EditUserID',
            'DateEdited',
            'Body',
            'Deleted'
        )
    );

    /**
     * Forum-specific export format
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->categories($port);
        $this->discussions($port);
        $this->conversations($port);
        $this->comments($port);
        $this->attachments($port);
    }

    /**
     * @param string $absPath
     * @return false|string
     */
    public function stripMediaPath($absPath): false|string
    {
        if (($pos = strpos($absPath, '/uploads/')) !== false) {
            return substr($absPath, $pos + 9);
        }

        return $absPath;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public function forceBool($value): bool
    {
        if ($value) {
            return true;
        }

        return false;
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
            'UserID' => 'UserID',
            'Name' => 'Name',
            'Password' => 'Password',
            'Email' => 'Email',
            'Icon' => 'Photo',
            'CountComments' => 'CountComments',
            'Discovery' => 'DiscoveryText'
        );
        $port->export('User', "SELECT * FROM :_User", $user_Map);  // ":_" will be replaced by database prefix
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        // Since the zero role is a valid role in Vanilla 1 then we'll have to reassign it.
        $r = $port->query('select max(RoleID) as RoleID from :_Role');
        $zeroRoleID = 0;
        if (is_object($r)) {
            while ($row = $r->nextResultRow()) {
                $zeroRoleID = $row['RoleID'];
            }
        }
        $zeroRoleID++;

        $role_Map = array(
            'RoleID' => 'RoleID',
            'Name' => 'Name',
            'Description' => 'Description'
        );
        $port->export(
            'Role',
            "select RoleID, Name, Description
                from :_Role
                union all
                select $zeroRoleID, 'Applicant', 'Created by Nitro Porter'",
            $role_Map
        );

        // UserRoles
        $userRole_Map = array(
            'UserID' => 'UserID',
            'RoleID' => 'RoleID'
        );
        $port->export(
            'UserRole',
            "select UserID, case RoleID when 0 then $zeroRoleID else RoleID end as RoleID from :_User",
            $userRole_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'CategoryID' => 'CategoryID',
            'Name' => 'Name',
            'Description' => 'Description'
        );
        $port->export('Category', "select CategoryID, Name, Description from :_Category", $category_Map);
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
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
            'LastUserID' => 'LastCommentUserID'
        );
        $port->export(
            'Discussion',
            "SELECT d.*,
                    d.LastUserID as LastCommentUserID,
                    d.DateCreated as DateCreated2, d.AuthUserID as AuthUserID2,
                    c.Body,
                    c.FormatType as Format
                FROM :_Discussion d
                LEFT JOIN :_Comment c
                    ON d.FirstCommentID = c.CommentID
                WHERE coalesce(d.WhisperUserID, 0) = 0 and d.Active = 1",
            $discussion_Map
        );

        $port->export(
            'UserDiscussion',
            "SELECT
                    w.UserID,
                    w.DiscussionID,
                    w.CountComments,
                    w.LastViewed as DateLastViewed,
                    case when b.UserID is not null then 1 else 0 end AS Bookmarked
                FROM :_UserDiscussionWatch w
                LEFT JOIN :_UserBookmark b
                    ON w.DiscussionID = b.DiscussionID AND w.UserID = b.UserID"
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'CommentID' => 'CommentID',
            'DiscussionID' => 'DiscussionID',
            'AuthUserID' => 'InsertUserID',
            'DateCreated' => 'DateInserted',
            'EditUserID' => 'UpdateUserID',
            'DateEdited' => 'DateUpdated',
            'Body' => 'Body',
            'FormatType' => 'Format'
        );
        $port->export(
            'Comment',
            "SELECT c.*
                 FROM :_Comment c
                 JOIN :_Discussion d
                    ON c.DiscussionID = d.DiscussionID
                 WHERE d.FirstCommentID <> c.CommentID
                    AND c.Deleted = '0'
                    AND coalesce(d.WhisperUserID, 0) = 0
                    AND coalesce(c.WhisperUserID, 0) = 0",
            $comment_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        // These mapping tables are used to group comments that a) are in the same discussion
        // and b) are from and to the same users.
        $port->query("drop table if exists z_pmto");
        $port->query(
            "create table z_pmto (
                    CommentID int,
                    UserID int,
                primary key(CommentID, UserID)
            )"
        );
        $port->query(
            "insert ignore z_pmto (CommentID, UserID)
                select distinct CommentID, AuthUserID
                from :_Comment
                where coalesce(WhisperUserID, 0) <> 0"
        );
        $port->query(
            "insert ignore z_pmto (CommentID, UserID)
                select distinct CommentID, WhisperUserID
                from :_Comment
                where coalesce(WhisperUserID, 0) <> 0"
        );
        $port->query(
            "insert ignore z_pmto (CommentID, UserID)
                select distinct c.CommentID, d.AuthUserID
                from :_Discussion d
                join :_Comment c
                    on c.DiscussionID = d.DiscussionID
                where coalesce(d.WhisperUserID, 0) <> 0"
        );
        $port->query(
            "insert ignore z_pmto (CommentID, UserID)
                select distinct c.CommentID, d.WhisperUserID
                from :_Discussion d
                join :_Comment c
                    on c.DiscussionID = d.DiscussionID
                where coalesce(d.WhisperUserID, 0) <> 0"
        );
        $port->query(
            "insert ignore z_pmto (CommentID, UserID)
                select distinct c.CommentID, c.AuthUserID
                from :_Discussion d
                join :_Comment c
                    on c.DiscussionID = d.DiscussionID
                where coalesce(d.WhisperUserID, 0) <> 0"
        );

        $port->query("drop table if exists z_pmto2");
        $port->query(
            "create table z_pmto2 (
              CommentID int,
              UserIDs varchar(250),
              primary key (CommentID)
            )"
        );
        $port->query(
            "insert z_pmto2 (CommentID, UserIDs)
                select CommentID, group_concat(UserID order by UserID)
                from z_pmto
                group by CommentID"
        );

        $port->query("drop table if exists z_pm");
        $port->query(
            "create table z_pm (
              CommentID int,
              DiscussionID int,
              UserIDs varchar(250),
              GroupID int
            )"
        );
        $port->query(
            "insert ignore z_pm (CommentID, DiscussionID)
                select CommentID, DiscussionID
                from :_Comment
                where coalesce(WhisperUserID, 0) <> 0"
        );
        $port->query(
            "insert ignore z_pm (CommentID, DiscussionID)
                select c.CommentID, c.DiscussionID
                from :_Discussion d
                join :_Comment c
                    on c.DiscussionID = d.DiscussionID
                where coalesce(d.WhisperUserID, 0) <> 0"
        );
        $port->query(
            "update z_pm pm
                join z_pmto2 t
                    on t.CommentID = pm.CommentID
                set pm.UserIDs = t.UserIDs"
        );

        $port->query("drop table if exists z_pmgroup");
        $port->query(
            "create table z_pmgroup (
                GroupID int,
                DiscussionID int,
                UserIDs varchar(250)
            )"
        );
        $port->query(
            "insert z_pmgroup (GroupID, DiscussionID, UserIDs)
                select min(pm.CommentID), pm.DiscussionID, t2.UserIDs
                from z_pm pm
                join z_pmto2 t2
                    on pm.CommentID = t2.CommentID
                group by pm.DiscussionID, t2.UserIDs"
        );

        $port->query("create index z_idx_pmgroup on z_pmgroup (DiscussionID, UserIDs)");
        $port->query("create index z_idx_pmgroup2 on z_pmgroup (GroupID)");

        $port->query(
            "update z_pm pm
                join z_pmgroup g
                    on pm.DiscussionID = g.DiscussionID and pm.UserIDs = g.UserIDs
                set pm.GroupID = g.GroupID"
        );

        $conversation_Map = array(
            'AuthUserID' => 'InsertUserID',
            'DateCreated' => 'DateInserted',
            'DiscussionID' => array('Column' => 'DiscussionID', 'Type' => 'int'),
            'CommentID' => 'ConversationID',
            'Name' => array('Column' => 'Subject', 'Type' => 'varchar(255)')
        );
        $port->export(
            'Conversation',
            "select c.*, d.Name
                from :_Comment c
                join :_Discussion d
                    on d.DiscussionID = c.DiscussionID
                join z_pmgroup g
                    on g.GroupID = c.CommentID;",
            $conversation_Map
        );

        // ConversationMessage.
        $conversationMessage_Map = array(
            'CommentID' => 'MessageID',
            'GroupID' => 'ConversationID',
            'Body' => 'Body',
            'FormatType' => 'Format',
            'AuthUserID' => 'InsertUserID',
            'DateCreated' => 'DateInserted'
        );
        $port->export(
            'ConversationMessage',
            "select c.*, pm.GroupID
                from z_pm pm
                join :_Comment c
                    on pm.CommentID = c.CommentID",
            $conversationMessage_Map
        );

        // UserConversation
        $userConversation_Map = array(
            'UserID' => 'UserID',
            'GroupID' => 'ConversationID'
        );
        $port->export(
            'UserConversation',
            "select distinct
                    pm.GroupID,
                    t.UserID
                from z_pmto t
                join z_pm pm
                    on pm.CommentID = t.CommentID",
            $userConversation_Map
        );

        $port->query("drop table z_pmto");
        $port->query("drop table z_pmto2");
        $port->query("drop table z_pm");
        $port->query("drop table z_pmgroup");
    }

    /**
     * @param Migration $port
     */
    protected function attachments(Migration $port): void
    {
        if ($port->hasInputSchema('Attachment')) {
            $media_Map = array(
                'AttachmentID' => 'MediaID',
                'Name' => 'Name',
                'MimeType' => 'Type',
                'Size' => 'Size',
                'Path' => array('Column' => 'Path', 'Filter' => array($this, 'stripMediaPath')),
                'UserID' => 'InsertUserID',
                'DateCreated' => 'DateInserted',
                'CommentID' => 'ForeignID'
                //'ForeignTable'
            );
            $port->export(
                'Media',
                "select a.*, 'comment' as ForeignTable from :_Attachment a",
                $media_Map
            );
        }
    }
}
