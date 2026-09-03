<?php

/**
 * YetAnotherForum.NET exporter tool
 *
 * @author  Todd Burry
 */

namespace Porter\Source;

use Porter\Source;

class Yaf extends Source
{
    public const array INFO = [
        'name' => 'YAF.NET',
        'defaultTablePrefix' => 'yaf_',
        'charsetTable' => 'Topic',
    ];

    protected function exportConversationTemps(): void
    {
        $sql = "drop table if exists z_pmto;
         create table z_pmto (
            PM_ID int unsigned,
            User_ID int,
            Deleted tinyint,
            primary key(PM_ID, User_ID) );
         insert ignore z_pmto (PM_ID, User_ID, Deleted)
         select PMessageID,  FromUserID, 0
         from :_PMessage;
         replace z_pmto (PM_ID, User_ID, Deleted)
         select PMessageID, UserID, IsDeleted
         from :_UserPMessage;

         drop table if exists z_pmto2;
         create table z_pmto2 (
            PM_ID int unsigned,
             UserIDs varchar(250),
             primary key (PM_ID) );

         replace z_pmto2 (PM_ID, UserIDs)
         select PM_ID, group_concat(User_ID order by User_ID)
         from z_pmto
         group by PM_ID;

         drop table if exists z_pmtext;
         create table z_pmtext (
            PM_ID int unsigned,
            Title varchar(250),
             Title2 varchar(250),
             UserIDs varchar(250),
             Group_ID int unsigned );

         insert z_pmtext (PM_ID, Title, Title2)
         select PMessageID, Subject,
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
                 UserIDs varchar(250) );
         insert z_pmgroup (Group_ID, Title, UserIDs)
               select min(pm.PM_ID), pm.Title2, t2.UserIDs
               from z_pmtext pm
               join z_pmto2 t2 on pm.PM_ID = t2.PM_ID
               group by pm.Title2, t2.UserIDs;
         create index z_idx_pmgroup on z_pmgroup (Title, UserIDs);
         create index z_idx_pmgroup2 on z_pmgroup (Group_ID);

         update z_pmtext pm
               join z_pmgroup g on pm.Title2 = g.Title and pm.UserIDs = g.UserIDs
               set pm.Group_ID = g.Group_ID;";

        $this->dbInput()->unprepared($sql);
    }

    protected function users(): void
    {
        $user_Map = [
            'UserID' => 'UserID',
            'Name' => 'Name',
            'Email' => 'Email',
            'Joined' => 'DateInserted',
            'LastVisit' => 'DateLastVisit',
            'IP' => 'InsertIPAddress',
            'Avatar' => 'Photo',
            'RankID' => 'RankID',
            'Points' => 'Points',
            'LastActivity' => 'DateLastActive',
            'HashMethod=yaf',
        ];
        $filters = [
            'Password' => \Porter\Filter\YafPassword::class,
        ];
        $this->export(
            'User',
            "select u.*, m.Password, m.PasswordSalt, m.PasswordFormat, m.LastActivity
                from :_User u
                left join :_prov_Membership m on u.ProviderUserKey = m.UserID;",
            $user_Map,
            $filters
        );
    }

    protected function roles(): void
    {
        $role_Map = [
            'GroupID' => 'RoleID',
            'Name' => 'Name'
        ];
        $this->export('Role', "select * from :_Group;", $role_Map);

        // UserRole.
        $userRole_Map = [
            'UserID' => 'UserID',
            'GroupID' => 'RoleID'
        ];
        $this->export('UserRole', 'select * from :_UserGroup', $userRole_Map);
    }

    protected function ranks(): void
    {
        $rank_Map = [
            'RankID' => 'RankID',
            'Level' => 'Level',
            'Name' => 'Name',
            'Label' => 'Label'
        ];
        $this->export(
            'Rank',
            "select r.*, RankID as Level, Name as Label from :_Rank r;",
            $rank_Map
        );
    }

    protected function signatures(): void
    {
        $this->export(
            'UserMeta',
            "select UserID, 'Plugin.Signatures.Sig' as `Name`, Signature as `Value`
                from :_User where Signature <> ''
                union all
                select UserID, 'Plugin.Signatures.Format' as `Name`, 'BBCode' as `Value`
                from :_User where Signature <> '';"
        );
    }

    protected function categories(): void
    {
        $category_Map = [
            'ForumID' => 'CategoryID',
            'ParentID' => 'ParentCategoryID',
            'Name' => 'Name',
            'Description' => 'Description',
            'SortOrder' => 'Sort'
        ];
        $this->export(
            'Category',
            "select f.ForumID,
                    case when f.ParentID = 0 then f.CategoryID * 1000 else f.ParentID end as ParentID,
                    f.Name,
                    f.Description,
                    f.SortOrder
                from :_Forum f
                union all
                select c.CategoryID * 1000,
                    null,
                    c.Name,
                    null,
                    c.SortOrder
                from :_Category c;",
            $category_Map
        );
    }

    protected function discussions(): void
    {
        $discussion_Map = [
            'TopicID' => 'DiscussionID',
            'ForumID' => 'CategoryID',
            'UserID' => 'InsertUserID',
            'Posted' => 'DateInserted',
            'Topic' => 'Name',
            'Views' => 'CountViews',
            'Announce' => 'Announce'
        ];
        $this->export(
            'Discussion',
            "select t.*, t.Flags & 1 as Closed,
                    case when t.Priority > 0 then 1 else 0 end as Announce
                from :_Topic t
                where t.IsDeleted = 0;",
            $discussion_Map
        );
    }

    protected function comments(): void
    {
        $map = [
            'MessageID' => 'CommentID',
            'TopicID' => 'DiscussionID',
            'ReplyTo' => 'ReplyToCommentID',
            'UserID' => 'InsertUserID',
            'Posted' => 'DateInserted',
            'Message' => 'Body',
            'Format' => 'Format',
            'IP' => 'InsertIPAddress',
            'Edited' => 'DateUpdated',
            'EditedBy' => 'UpdateUserID'
        ];
        $filters = [
            'Edited' => function ($value) {
                if (empty($value) || str_starts_with($value, '0000')) {
                    return null;
                }
                return $value;
            }
        ];
        $this->export(
            'Comment',
            "select m.*, case when m.Flags & 1 = 1 then 'Html' else 'BBCode' end as Format
                from :_Message m
                where IsDeleted = 0;",
            $map,
            $filters
        );
    }

    protected function conversations(): void
    {
        $this->exportConversationTemps();
        $map = [
            'PMessageID' => 'ConversationID',
            'FromUserID' => 'InsertUserID',
            'Created' => 'DateInserted',
            'Title' => 'Subject',
        ];
        $this->export(
            'Conversation',
            "select pm.*, g.Title
                from z_pmgroup g
                join :_PMessage pm on g.Group_ID = pm.PMessageID;",
            $map
        );

        // UserConversation.
        $userConversation_Map = [
            'PM_ID' => 'ConversationID',
            'User_ID' => 'UserID',
            'Deleted' => 'Deleted'
        ];
        $this->export(
            'UserConversation', // @todo WTF join?
            "select pto.*
                from z_pmto pto
                join z_pmgroup g on pto.PM_ID = g.Group_ID;",
            $userConversation_Map
        );

        // ConversationMessage.
        $conversationMessage_Map = [
            'PMessageID' => 'MessageID',
            'Group_ID' => 'ConversationID',
            'FromUserID' => 'InsertUserID',
            'Created' => 'DateInserted',
            'Body' => 'Body',
            'Format' => 'Format'
        ];
        $this->export(
            'ConversationMessage',
            "select pm.*, t.Group_ID, case when pm.Flags & 1 = 1 then 'Html' else 'BBCode' end as Format
            from :_PMessage pm
            join z_pmtext t on t.PM_ID = pm.PMessageID;",
            $conversationMessage_Map
        );
    }
}
