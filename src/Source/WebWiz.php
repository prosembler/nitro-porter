<?php

/**
 * WebWiz exporter tool
 *
 * @author  Todd Burry
 */

namespace Porter\Source;

use Porter\Source;

class WebWiz extends Source
{
    public const array INFO = [
        'name' => 'Web Wiz Forums',
        'defaultTablePrefix' => 'tbl',
        'charsetTable' => 'Topic',
    ];

    public function conversations(): void
    {
        $this->exportConversationTemps();

        // Conversation.
        $conversation_Map = [
            'PM_ID' => 'ConversationID',
            'Title' => ['Column' => 'Subject', 'Type' => 'varchar(255)'],
            'Author_ID' => 'InsertUserID',
            'PM_Message_Date' => ['Column' => 'DateInserted']
        ];
        $this->export(
            'Conversation',
            "select pm.*,
                    g.Title
                 from :_PMMessage pm
                 join z_pmgroup g
                    on pm.PM_ID = g.Group_ID;",
            $conversation_Map
        );

        // User Conversation.
        $userConversation_Map = [
            'Group_ID' => 'ConversationID',
            'User_ID' => 'UserID'
        ];
        $this->export(
            'UserConversation',
            "select
                    g.Group_ID,
                    t.User_ID
                 from z_pmto t
                 join z_pmgroup g
                    on g.Group_ID = t.PM_ID;",
            $userConversation_Map
        );

        // Conversation Message.
        $message_Map = [
            'Group_ID' => 'ConversationID',
            'PM_ID' => 'MessageID',
            'PM_Message' => 'Body',
            'Format' => 'Format',
            'PM_Message_Date' => ['Column' => 'DateInserted'],
            'Author_ID' => 'InsertUserID'
        ];
        $this->export(
            'ConversationMessage',
            "select pm.*,
                    pm2.Group_ID,
                    'Html' as Format
                from :_PMMessage pm
                join z_pmtext pm2
                    on pm.PM_ID = pm2.PM_ID;",
            $message_Map
        );
    }

    protected function exportConversationTemps(): void
    {
        $sql = "
            drop table if exists z_pmto;
            create table z_pmto (
                PM_ID int unsigned,
                User_ID int,
                primary key(PM_ID, User_ID)
            );
            insert ignore z_pmto (PM_ID, User_ID)
            select PM_ID, Author_ID
            from :_PMMessage;

            insert ignore z_pmto (PM_ID, User_ID)
            select PM_ID, From_ID
            from :_PMMessage;

            drop table if exists z_pmto2;
            create table z_pmto2 (
                PM_ID int unsigned,
                UserIDs varchar(250),
                primary key (PM_ID)
            );

            replace z_pmto2 ( PM_ID, UserIDs)
            select PM_ID, group_concat(User_ID order by User_ID)
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

            insert z_pmtext (PM_ID, Title, Title2)
            select  PM_ID, PM_Tittle,
                case when PM_Tittle like 'Re:%' then trim(substring(PM_Tittle, 4)) else PM_Tittle end as Title2
            from :_PMMessage;

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

            insert z_pmgroup (Group_ID, Title, UserIDs)
            select min(pm.PM_ID), pm.Title2, t2.UserIDs
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

        $this->dbInput()->unprepared($sql);
    }

    protected function users(): void
    {
        $user_Map = [
            'Author_ID' => 'UserID',
            'Username' => ['Column' => 'Name', 'Filter' => 'DecodeHtml'],
            'Real_name' => ['Column' => 'FullName', 'Type' => 'varchar(50)', 'Filter' => 'DecodeHtml'],
            'Password2' => 'Password',
            'Gender2' => 'Gender',
            'Author_email' => 'Email',
            'Photo2' => ['Column' => 'Photo', 'Filter' => 'DecodeHtml'],
            'Login_IP' => 'LastIPAddress',
            'Banned' => 'Banned',
            'Join_date' => ['Column' => 'DateInserted'],
            'Last_visit' => ['Column' => 'DateLastActive'],
            'Location' => ['Column' => 'Location', 'Filter' => 'DecodeHtml'],
            'DOB' => 'DateOfBirth',
            'Show_email' => 'ShowEmail'
        ];
        $this->export(
            'User',
            "select
                    concat(Salt, '$', Password) as Password2,
                    case u.Gender when 'Male' then 'm' when 'Female' then 'f' else 'u' end as Gender2,
                case when Avatar like 'http%' then Avatar when Avatar > ''
                    then concat('webwiz/', Avatar) else null end as Photo2,
                    'webwiz' as HashMethod,
                    u.*
                from :_Author u",
            $user_Map
        );
    }

    protected function roles(): void
    {
        $role_Map = [
            'Group_ID' => 'RoleID',
            'Name' => 'Name'
        ];
        $this->export(
            'Role',
            "select * from :_Group",
            $role_Map
        );

        // User Role.
        $userRole_Map = [
            'Author_ID' => 'UserID',
            'Group_ID' => 'RoleID'
        ];
        $this->export(
            'UserRole',
            "select u.* from :_Author u",
            $userRole_Map
        );
    }

    protected function signatures(): void
    {
        $this->export(
            'UserMeta',
            "select
                Author_ID as UserID,
                'Plugin.Signatures.Sig' as `Name`,
                Signature as `Value`
            from :_Author
            where Signature <> ''"
        );
    }

    protected function categories(): void
    {
        $category_Map = [
            'Forum_ID' => 'CategoryID',
            'Forum_name' => 'Name',
            'Forum_description' => 'Description',
            'Parent_ID' => 'ParentCategoryID',
            'Forum_order' => 'Sort'
        ];
        $this->export(
            'Category',
            "select
                    f.Forum_ID,
                    f.Cat_ID * 1000 as Parent_ID,
                    f.Forum_order,
                    f.Forum_name,
                    f.Forum_description
                from :_Forum f
                union all
                select
                    c.Cat_ID * 1000,
                    null,
                    c.Cat_order,
                    c.Cat_name,
                    null
                from :_Category c",
            $category_Map
        );
    }

    protected function discussions(): void
    {
        $discussion_Map = [
            'Topic_ID' => 'DiscussionID',
            'Forum_ID' => 'CategoryID',
            'Author_ID' => 'InsertUserID',
            'Subject' => ['Column' => 'Name', 'Filter' => 'DecodeHtml'],
            'IP_addr' => 'InsertIPAddress',
            'Message' => ['Column' => 'Body'],
            'Format' => 'Format',
            'Message_date' => ['Column' => 'DateInserted'],
            'No_of_views' => 'CountViews',
            'Locked' => 'Closed',

        ];
        $this->export(
            'Discussion',
            "select
                    th.Author_ID,
                    th.Message,
                    th.Message_date,
                    th.IP_addr,
                    'Html' as Format,
                    t.*
                from :_Topic t
                join :_Thread th
                    on t.Start_Thread_ID = th.Thread_ID",
            $discussion_Map
        );
    }

    protected function comments(): void
    {
        $comment_Map = [
            'Thread_ID' => 'CommentID',
            'Topic_ID' => 'DiscussionID',
            'Author_ID' => 'InsertUserID',
            'IP_addr' => 'InsertIPAddress',
            'Message' => ['Column' => 'Body'],
            'Format' => 'Format',
            'Message_date' => ['Column' => 'DateInserted']
        ];
        $this->export(
            'Comment',
            "select th.*, 'Html' as Format
                from :_Thread th
                join :_Topic t
                    on t.Topic_ID = th.Topic_ID
                where th.Thread_ID <> t.Start_Thread_ID",
            $comment_Map
        );
    }
}
