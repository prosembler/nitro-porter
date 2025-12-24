<?php

/**
 * WebWiz exporter tool
 *
 * @author  Todd Burry
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class WebWiz extends Source
{
    public const SUPPORTED = [
        'name' => 'Web Wiz Forums',
        'defaultTablePrefix' => 'tbl',
        'charsetTable' => 'Topic',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 1,
            'Signatures' => 1,
        ]
    ];

    /**
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->usermeta($port);

        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->conversations($port);
    }

    /**
     * @param Migration $port
     */
    public function conversations(Migration $port): void
    {
        $this->exportConversationTemps($port);

        // Conversation.
        $conversation_Map = array(
            'PM_ID' => 'ConversationID',
            'Title' => array('Column' => 'Subject', 'Type' => 'varchar(255)'),
            'Author_ID' => 'InsertUserID',
            'PM_Message_Date' => array('Column' => 'DateInserted')
        );
        $port->export(
            'Conversation',
            "select pm.*,
                    g.Title
                 from :_PMMessage pm
                 join z_pmgroup g
                    on pm.PM_ID = g.Group_ID;",
            $conversation_Map
        );

        // User Conversation.
        $userConversation_Map = array(
            'Group_ID' => 'ConversationID',
            'User_ID' => 'UserID'
        );
        $port->export(
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
        $message_Map = array(
            'Group_ID' => 'ConversationID',
            'PM_ID' => 'MessageID',
            'PM_Message' => 'Body',
            'Format' => 'Format',
            'PM_Message_Date' => array('Column' => 'DateInserted'),
            'Author_ID' => 'InsertUserID'
        );
        $port->export(
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
                primary key(PM_ID, User_ID)
            );
            insert ignore z_pmto (
                PM_ID,
                User_ID
            )
            select
                PM_ID,
                Author_ID
            from :_PMMessage;

            insert ignore z_pmto (
                PM_ID,
                User_ID
            )
            select
                PM_ID,
                From_ID
            from :_PMMessage;

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
                PM_ID,
                PM_Tittle,
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
            'Author_ID' => 'UserID',
            'Username' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'Real_name' => array('Column' => 'FullName', 'Type' => 'varchar(50)', 'Filter' => 'HTMLDecoder'),
            'Password2' => 'Password',
            'Gender2' => 'Gender',
            'Author_email' => 'Email',
            'Photo2' => array('Column' => 'Photo', 'Filter' => 'HTMLDecoder'),
            'Login_IP' => 'LastIPAddress',
            'Banned' => 'Banned',
            'Join_date' => array('Column' => 'DateInserted'),
            'Last_visit' => array('Column' => 'DateLastActive'),
            'Location' => array('Column' => 'Location', 'Filter' => 'HTMLDecoder'),
            'DOB' => 'DateOfBirth',
            'Show_email' => 'ShowEmail'
        );
        $port->export(
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

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'Group_ID' => 'RoleID',
            'Name' => 'Name'
        );
        $port->export(
            'Role',
            "select * from :_Group",
            $role_Map
        );

        // User Role.
        $userRole_Map = array(
            'Author_ID' => 'UserID',
            'Group_ID' => 'RoleID'
        );
        $port->export(
            'UserRole',
            "select u.* from :_Author u",
            $userRole_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function usermeta(Migration $port): void
    {
        $port->export(
            'UserMeta',
            "select
                Author_ID as UserID,
                'Plugin.Signatures.Sig' as `Name`,
                Signature as `Value`
            from :_Author
            where Signature <> ''"
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'Forum_ID' => 'CategoryID',
            'Forum_name' => 'Name',
            'Forum_description' => 'Description',
            'Parent_ID' => 'ParentCategoryID',
            'Forum_order' => 'Sort'
        );
        $port->export(
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

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'Topic_ID' => 'DiscussionID',
            'Forum_ID' => 'CategoryID',
            'Author_ID' => 'InsertUserID',
            'Subject' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'IP_addr' => 'InsertIPAddress',
            'Message' => array('Column' => 'Body'),
            'Format' => 'Format',
            'Message_date' => array('Column' => 'DateInserted'),
            'No_of_views' => 'CountViews',
            'Locked' => 'Closed',

        );
        $port->export(
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

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'Thread_ID' => 'CommentID',
            'Topic_ID' => 'DiscussionID',
            'Author_ID' => 'InsertUserID',
            'IP_addr' => 'InsertIPAddress',
            'Message' => array('Column' => 'Body'),
            'Format' => 'Format',
            'Message_date' => array('Column' => 'DateInserted')
        );
        $port->export(
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
