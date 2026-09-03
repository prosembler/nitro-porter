<?php

/**
 * phpBB exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

class PhpBb2 extends Source
{
    public const array INFO = [
        'name' => 'phpBB 2',
        'defaultTablePrefix' => 'phpbb_',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'phpBB',
    ];

    public array $sourceTables = [
        'users' => [
            'user_id',
            'username',
            'user_password',
            'user_email',
            'user_timezone',
            'user_posts',
            'user_regdate',
            'user_lastvisit'
        ],
        'groups' => ['group_id', 'group_name', 'group_description'],
        'user_group' => ['user_id', 'group_id'],
        'forums' => ['forum_id', 'forum_name', 'forum_desc', 'forum_order'],
        'topics' => [
            'topic_id',
            'forum_id',
            'topic_poster',
            'topic_title',
            'topic_views',
            'topic_first_post_id',
            'topic_status',
            'topic_type',
            'topic_time'
        ],
        'posts' => ['post_id', 'topic_id', 'poster_id', 'post_time', 'post_edit_time'],
        'posts_text' => ['post_id', 'post_text'],
        'privmsgs' => [
            'privmsgs_id',
            'privmsgs_subject',
            'privmsgs_from_userid',
            'privmsgs_to_userid',
            'privmsgs_date'
        ],
        'privmsgs_text' => ['privmsgs_text_id', 'privmsgs_bbcode_uid', 'privmsgs_text']
    ];

    protected function attachments(): void
    {
        $this->export(
            'Media',
            "select ad.attach_id as MediaID,
                    ad.real_filename as Name,
                    concat('attachments/',ad.physical_filename) as Path,
                    concat('attachments/',ad.physical_filename) as ThumbPath,
                    if(ad.mimetype = '', 'application/octet-stream', ad.mimetype) as Type,
                    ad.filesize as Size,
                    FROM_UNIXTIME(ad.filetime) as DateInserted,
                    ifnull(t.topic_id, a.post_id) as ForeignID,
                    if(t.topic_id is not null, 'discussion', 'comment') as ForeignTable,
                    a.user_id_1 as InsertUserID
                from :_attachments_desc ad
                inner join :_attachments a on a.attach_id = ad.attach_id
                left join :_topics t on t.topic_first_post_id = a.post_id"
        );
    }

    protected function users(): void
    {
        $map = [
            'user_id' => 'UserID',
            'username' => 'Name',
            'user_password' => 'Password',
            'user_email' => 'Email',
            'user_posts' => ['Column' => 'CountComments', 'Type' => 'int'],
            'user_lastvisit' => 'DateLastActive',
            'user_regdate' => 'DateInserted',
        ];
        $filters = [
            'user_lastvisit' => \Porter\Filter\UnixtimeToDate::class,
            'user_regdate' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export('User', "select *, from :_users", $map, $filters);
    }

    protected function roles(): void
    {
        $role_Map = [
            'group_id' => 'RoleID',
            'group_name' => 'Name',
            'group_description' => 'Description'
        ];
        // Skip single-user groups
        $this->export('Role', 'select * from :_groups where group_single_user = 0', $role_Map);

        // UserRoles
        $map = [
            'user_id' => 'UserID',
            'group_id' => 'RoleID'
        ];
        // Skip pending memberships
        $this->export('UserRole', 'select user_id, group_id from :_user_group where user_pending = 0;', $map);
    }

    protected function categories(): void
    {
        $category_Map = [
            'id' => 'CategoryID',
            'cat_title' => 'Name',
            'description' => 'Description',
            'parentid' => 'ParentCategoryID'
        ];
        $this->export(
            'Category',
            "select
                    c.cat_id * 1000 as id,
                    c.cat_title,
                    c.cat_order * 1000 as Sort,
                    null as parentid,
                    '' as description
                from :_categories c
                union all
                select
                    f.forum_id,
                    f.forum_name,
                    c.cat_order * 1000 + f.forum_order,
                    c.cat_id * 1000 as parentid,
                    f.forum_desc
                from :_forums f
                left join :_categories c on f.cat_id = c.cat_id",
            $category_Map
        );
    }

    protected function discussions(): void
    {
        $discussion_Map = [
            'topic_id' => 'DiscussionID',
            'forum_id' => 'CategoryID',
            'topic_poster' => 'InsertUserID',
            'topic_title' => 'Name',
            'topic_views' => 'CountViews',
            'Format=BBCode',
        ];

        $this->export(
            'Discussion',
            "select t.*,
                    case t.topic_status when 1 then 1 else 0 end as Closed,
                    case t.topic_type when 1 then 2 else 0 end as Announce,
                    FROM_UNIXTIME(t.topic_time) as DateInserted
                from :_topics t",
            $discussion_Map
        );
    }

    protected function comments(): void
    {
        $map = [
            'post_id' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'post_text' => 'Body',
            'poster_id' => 'InsertUserID',
            'post_time' => 'DateInserted',
            'post_edit_time' => 'DateUpdated',
            'Format=BBCode',
        ];
        $filters = [
            'post_text' => \Porter\Filter\RemoveBbCodeUidsSimple::class,
            'post_time' => \Porter\Filter\UnixtimeToDate::class,
            'post_edit_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Comment',
            "select p.*, pt.post_text, pt.bbcode_uid
                from :_posts p 
                inner join :_posts_text pt on p.post_id = pt.post_id",
            $map,
            $filters
        );
    }

    protected function conversations(): void
    {
        $this->dbInput()->unprepared("drop table if exists z_pmto;");
        $this->dbInput()->unprepared("create table z_pmto (
                id int unsigned,
                userid int unsigned,
                primary key(id, userid));");
        $this->dbInput()->unprepared("insert ignore z_pmto (id, userid)
                select privmsgs_id, privmsgs_from_userid
                from :_privmsgs;");
        $this->dbInput()->unprepared("insert ignore z_pmto (id, userid)
                select privmsgs_id, privmsgs_to_userid
                from :_privmsgs;");

        $this->dbInput()->unprepared("drop table if exists z_pmto2;");
        $this->dbInput()->unprepared("create table z_pmto2 (
                id int unsigned,
                userids varchar(250),
                primary key (id));");
        $this->dbInput()->unprepared("insert ignore z_pmto2 (id, userids)
                select id, group_concat(userid order by userid)
                from z_pmto
                group by id;");

        $this->dbInput()->unprepared("drop table if exists z_pm;");
        $this->dbInput()->unprepared("create table z_pm (
                id int unsigned,
                subject varchar(255),
                subject2 varchar(255),
                userids varchar(250),
                groupid int unsigned);");
        $this->dbInput()->unprepared("insert z_pm (id, subject, subject2, userids)
                select pm.privmsgs_id,
                    pm.privmsgs_subject,
                    case when pm.privmsgs_subject like 'Re: %' then trim(substring(pm.privmsgs_subject, 4))
                        else pm.privmsgs_subject end as subject2,
                    t.userids
                from :_privmsgs pm
                join z_pmto2 t on t.id = pm.privmsgs_id;");
        $this->dbInput()->unprepared("create index z_idx_pm on z_pm (id);");

        $this->dbInput()->unprepared("drop table if exists z_pmgroup;");
        $this->dbInput()->unprepared("create table z_pmgroup (
                groupid int unsigned,
                subject varchar(255),
                userids varchar(250));");
        $this->dbInput()->unprepared("insert z_pmgroup (groupid, subject, userids)
                select  min(pm.id), pm.subject2, pm.userids
                from z_pm pm
                group by pm.subject2, pm.userids;");

        $this->dbInput()->unprepared("create index z_idx_pmgroup on z_pmgroup (subject, userids);");
        $this->dbInput()->unprepared("create index z_idx_pmgroup2 on z_pmgroup (groupid);");
        $this->dbInput()->unprepared("update z_pm pm
                join z_pmgroup g on pm.subject2 = g.subject and pm.userids = g.userids
                set pm.groupid = g.groupid;");

        // Conversations.
        $map = [
            'privmsgs_id' => 'ConversationID',
            'privmsgs_from_userid' => 'InsertUserID',
            'realsubject' => 'Subject',
            'privmsgs_date' => 'DateInserted',
        ];
        $filters = [
            'realsubject' => \Porter\Filter\DecodeHtml::class,
            'privmsgs_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Conversation',
            "select pm.*, g.subject as realsubject
                from :_privmsgs pm
                join z_pmgroup g on g.groupid = pm.privmsgs_id",
            $map,
            $filters
        );

        // Coversation Messages.
        $conversationMessage_Map = [
            'privmsgs_id' => 'MessageID',
            'groupid' => 'ConversationID',
            'privmsgs_text' => 'Body',
            'privmsgs_from_userid' => 'InsertUserID',
            'privmsgs_date' => 'DateInserted',
            'Format=BBCode',
        ];
        $filters = [
            'post_text' => \Porter\Filter\RemoveBbCodeUidsSimple::class,
            'privmsgs_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'ConversationMessage',
            "select pm.*, txt.*, txt.privmsgs_bbcode_uid as bbcode_uid, pm2.groupid
                from :_privmsgs pm
                join :_privmsgs_text txt on pm.privmsgs_id = txt.privmsgs_text_id
                join z_pm pm2 on pm.privmsgs_id = pm2.id",
            $conversationMessage_Map,
            $filters
        );

        // User Conversation.
        $userConversation_Map = [
            'userid' => 'UserID',
            'groupid' => 'ConversationID'
        ];
        $this->export(
            'UserConversation',
            "select g.groupid, t.userid from z_pmto t join z_pmgroup g on g.groupid = t.id",
            $userConversation_Map
        );

        $this->dbInput()->unprepared('drop table if exists z_pmto');
        $this->dbInput()->unprepared('drop table if exists z_pmto2;');
        $this->dbInput()->unprepared('drop table if exists z_pm;');
        $this->dbInput()->unprepared('drop table if exists z_pmgroup;');
    }
}
