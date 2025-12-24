<?php

/**
 * YetAnotherForum.NET exporter tool
 *
 * @author  Todd Burry
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class Yaf extends Source
{
    public const SUPPORTED = [
        'name' => 'YAF.NET',
        'defaultTablePrefix' => 'yaf_',
        'charsetTable' => 'Topic',
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
            'Signatures' => 1,
            'Attachments' => 0,
            'Bookmarks' => 0,
            'Badges' => 0,
            'UserNotes' => 0,
            'Ranks' => 1,
        ]
    ];

    public static array $passwordFormats = array(0 => 'md5', 1 => 'sha1', 2 => 'sha256', 3 => 'sha384', 4 => 'sha512');

    /**
     * Main export method.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->ranks($port);
        $this->signatures($port);

        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->conversations($port);
    }

    public function cleanDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        if (substr($value, 0, 4) == '0000') {
            return null;
        }

        return $value;
    }

    public function convertPassword(string $hash, string $columnName, array &$row): string
    {
        $salt = $row['PasswordSalt'];
        $hash = $row['Password2'];
        $method = $row['PasswordFormat'];
        if (isset(self::$passwordFormats[$method])) {
            $method = self::$passwordFormats[$method];
        } else {
            $method = 'sha1';
        }
        $result = $method . '$' . $salt . '$' . $hash . '$';

        return $result;
    }

    /**
     * @param Migration $port
     */
    protected function exportConversationTemps(Migration $port): void
    {
        $sql = "
         drop table if exists z_pmto;
         create table z_pmto (
            PM_ID int unsigned,
            User_ID int,
            Deleted tinyint,
            primary key(PM_ID, User_ID)
            );
         insert ignore z_pmto (
            PM_ID,
            User_ID,
            Deleted
         )
         select
            PMessageID,
            FromUserID,
            0
         from :_PMessage;

         replace z_pmto (
            PM_ID,
            User_ID,
            Deleted
         )
         select
            PMessageID,
            UserID,
            IsDeleted
         from :_UserPMessage;

         drop table if exists z_pmto2;
         create table z_pmto2 (
            PM_ID int unsigned,
             UserIDs varchar(250),
             primary key (PM_ID)
         );

         replace z_pmto2 (
            PM_ID,
            UserIDs
         )
         select
            PM_ID,
            group_concat(User_ID order by User_ID)
         from z_pmto
         group by PM_ID;

         drop table if exists z_pmtext;
         create table z_pmtext (
            PM_ID int unsigned,
            Title varchar(250),
             Title2 varchar(250),
             UserIDs varchar(250),
             Group_ID int unsigned
         );

         insert z_pmtext (
            PM_ID,
            Title,
            Title2
         )
         select
            PMessageID,
            Subject,
            case when Subject like 'Re:%' then trim(substring(Subject, 4)) else Subject end as Title2
         from :_PMessage;

         create index z_idx_pmtext on z_pmtext (PM_ID);

         update z_pmtext pm
         join z_pmto2 t
            on pm.PM_ID = t.PM_ID
         set pm.UserIDs = t.UserIDs;

         drop table if exists z_pmgroup;

         create table z_pmgroup (
                 Group_ID int unsigned,
                 Title varchar(250),
                 UserIDs varchar(250)
               );

         insert z_pmgroup (
                 Group_ID,
                 Title,
                 UserIDs
               )
               select
                 min(pm.PM_ID),
                 pm.Title2,
                 t2.UserIDs
               from z_pmtext pm
               join z_pmto2 t2
                 on pm.PM_ID = t2.PM_ID
               group by pm.Title2, t2.UserIDs;

         create index z_idx_pmgroup on z_pmgroup (Title, UserIDs);
         create index z_idx_pmgroup2 on z_pmgroup (Group_ID);

         update z_pmtext pm
               join z_pmgroup g
                 on pm.Title2 = g.Title and pm.UserIDs = g.UserIDs
               set pm.Group_ID = g.Group_ID;";

        $port->dbInput()->unprepared($sql);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
            'UserID' => 'UserID',
            'Name' => 'Name',
            'Email' => 'Email',
            'Joined' => 'DateInserted',
            'LastVisit' => array('Column' => 'DateLastVisit', 'Type' => 'datetime'),
            'IP' => 'InsertIPAddress',
            'Avatar' => 'Photo',
            'RankID' => array('Column' => 'RankID', 'Type' => 'int'),
            'Points' => array('Column' => 'Points', 'Type' => 'int'),
            'LastActivity' => 'DateLastActive',
            'Password2' => array('Column' => 'Password', 'Filter' => array($this, 'convertPassword')),
            'HashMethod' => 'HashMethod'
        );
        $port->export(
            'User',
            "select u.*,
                    m.Password as Password2,
                    m.PasswordSalt,
                    m.PasswordFormat,
                    m.LastActivity,
                    'yaf' as HashMethod
                from :_User u
                left join :_prov_Membership m
                    on u.ProviderUserKey = m.UserID;",
            $user_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'GroupID' => 'RoleID',
            'Name' => 'Name'
        );
        $port->export(
            'Role',
            "select * from :_Group;",
            $role_Map
        );

        // UserRole.
        $userRole_Map = array(
            'UserID' => 'UserID',
            'GroupID' => 'RoleID'
        );
        $port->export('UserRole', 'select * from :_UserGroup', $userRole_Map);
    }

    /**
     * @param Migration $port
     */
    protected function ranks(Migration $port): void
    {
        $rank_Map = array(
            'RankID' => 'RankID',
            'Level' => 'Level',
            'Name' => 'Name',
            'Label' => 'Label'
        );
        $port->export(
            'Rank',
            "select r.*,
                    RankID as Level,
                    Name as Label
                from :_Rank r;",
            $rank_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function signatures(Migration $port): void
    {
        $port->export(
            'UserMeta',
            "select
                    UserID,
                    'Plugin.Signatures.Sig' as `Name`,
                    Signature as `Value`
                from :_User
                where Signature <> ''
                union all
                select
                    UserID,
                    'Plugin.Signatures.Format' as `Name`,
                    'BBCode' as `Value`
                from :_User
                where Signature <> '';"
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'ForumID' => 'CategoryID',
            'ParentID' => 'ParentCategoryID',
            'Name' => 'Name',
            'Description' => 'Description',
            'SortOrder' => 'Sort'
        );

        $port->export(
            'Category',
            "select
                    f.ForumID,
                    case when f.ParentID = 0 then f.CategoryID * 1000 else f.ParentID end as ParentID,
                    f.Name,
                    f.Description,
                    f.SortOrder
                from :_Forum f
                union all
                select
                    c.CategoryID * 1000,
                    null,
                    c.Name,
                    null,
                    c.SortOrder
                from :_Category c;",
            $category_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'TopicID' => 'DiscussionID',
            'ForumID' => 'CategoryID',
            'UserID' => 'InsertUserID',
            'Posted' => 'DateInserted',
            'Topic' => 'Name',
            'Views' => 'CountViews',
            'Announce' => 'Announce'
        );
        $port->export(
            'Discussion',
            "select
                    case when t.Priority > 0 then 1 else 0 end as Announce,
                    t.Flags & 1 as Closed,
                    t.*
                from :_Topic t
                where t.IsDeleted = 0;",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'MessageID' => 'CommentID',
            'TopicID' => 'DiscussionID',
            'ReplyTo' => array('Column' => 'ReplyToCommentID', 'Type' => 'int'),
            'UserID' => 'InsertUserID',
            'Posted' => 'DateInserted',
            'Message' => 'Body',
            'Format' => 'Format',
            'IP' => 'InsertIPAddress',
            'Edited' => array('Column' => 'DateUpdated', 'Filter' => array($this, 'cleanDate')),
            'EditedBy' => 'UpdateUserID'
        );
        $port->export(
            'Comment',
            "select m.*,
                    case when m.Flags & 1 = 1 then 'Html' else 'BBCode' end as Format
                from :_Message m
                where IsDeleted = 0;",
            $comment_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        $this->exportConversationTemps($port);

        $conversation_Map = array(
            'PMessageID' => 'ConversationID',
            'FromUserID' => 'InsertUserID',
            'Created' => 'DateInserted',
            'Title' => array('Column' => 'Subject', 'Type' => 'varchar(512)')
        );
        $port->export(
            'Conversation',
            "select pm.*, g.Title
                from z_pmgroup g
                join :_PMessage pm
                    on g.Group_ID = pm.PMessageID;",
            $conversation_Map
        );

        // UserConversation.
        $userConversation_Map = array(
            'PM_ID' => 'ConversationID',
            'User_ID' => 'UserID',
            'Deleted' => 'Deleted'
        );
        $port->export(
            'UserConversation',
            "select pto.*
                from z_pmto pto
                join z_pmgroup g
                on pto.PM_ID = g.Group_ID;",
            $userConversation_Map
        );

        // ConversationMessage.
        $conversationMessage_Map = array(
            'PMessageID' => 'MessageID',
            'Group_ID' => 'ConversationID',
            'FromUserID' => 'InsertUserID',
            'Created' => 'DateInserted',
            'Body' => 'Body',
            'Format' => 'Format'
        );
        $port->export(
            'ConversationMessage',
            "select pm.*,
                    case when pm.Flags & 1 = 1 then 'Html' else 'BBCode' end as Format,
                    t.Group_ID
            from :_PMessage pm
            join z_pmtext t
                on t.PM_ID = pm.PMessageID;",
            $conversationMessage_Map
        );
    }
}
