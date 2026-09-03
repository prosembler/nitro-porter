<?php

/**
 * phpBB exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;

class PhpBb3 extends Source
{
    public const array INFO = [
        'name' => 'phpBB 3',
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
            'user_lastvisit',
            'user_regdate'
        ],
        'groups' => ['group_id', 'group_name', 'group_desc'],
        'user_group' => ['user_id', 'group_id'],
        'forums' => ['forum_id', 'forum_name', 'forum_desc', 'left_id', 'parent_id'],
        'topics' => [
            'topic_id',
            'forum_id',
            'topic_poster',
            'topic_title',
            'topic_views',
            'topic_first_post_id',
            'topic_status',
            'topic_type',
            'topic_time',
            'topic_last_post_time',
            'topic_last_post_time'
        ],
        'posts' => [
            'post_id',
            'topic_id',
            'post_text',
            'poster_id',
            'post_edit_user',
            'post_time',
            'post_edit_time'
        ],
        'bookmarks' => ['user_id', 'topic_id']
    ];

    protected function usernotes(): void
    {
        $corruptedRecords = [];
        $map = [
            'log_id' => 'UserNoteID',
            'user_id' => 'InsertUserID',
            'reportee_id' => 'UserID',
            'log_ip' => 'InsertIPAddress',
            'log_time' => 'DateInserted',
            'log_operation' => 'Type',
            'log_data' => 'Body',
            'Format=Text',
        ];
        $filters = [
            'log_time' => \Porter\Filter\UnixtimeToDate::class,
            'log_operation' => function ($value, $field, $row) {
                switch (strtoupper($value)) {
                    case 'LOG_USER_WARNING_BODY':
                        return 'warning';
                    default:
                        return 'note';
                }
            },
            'log_data' => function ($value, $field, $row) use (&$corruptedRecords) {
                $unserializedValue = @unserialize($value);
                if (!$unserializedValue || !is_array($unserializedValue)) {
                    $corruptedRecords[] = $row['log_id'];
                    return '';
                }
                return array_pop($unserializedValue);
            }
        ];
        $this->export(
            'UserNote',
            "select * from :_log
                where reportee_id > 0 and log_operation in ('LOG_USER_GENERAL', 'LOG_USER_WARNING_BODY')",
            $map,
            $filters
        );
        if (count($corruptedRecords) > 0) {
            Log::comment("Corrupted records found in \"_log\" table while exporting to UserNote\n"
                . print_r($corruptedRecords, true));
        }
    }

    protected function users(): void
    {
        // Grab the avatar salt.
        //$data = $port->get("select config_value from :_config where config_name = 'avatar_salt'");
        $data = $this->dbInput()->table('config')->select(['config_value'])
            ->where('config_name', '=', 'avatar_salt')->first();
        $px = $data->config_value ?? '';

        $user_Map = [
            'user_id' => 'UserID',
            'username' => 'Name',
            'user_password' => 'Password',
            'user_email' => 'Email',
            'user_posts' => 'CountComments',
            'user_rank' => 'RankID',
            'user_ip' => 'LastIPAddress',
            'user_regdate' => 'DateInserted',
            'user_lastvisit' => 'DateLastVisit',
        ];
        $filters = [
            'username' => \Porter\Filter\DecodeHtml::class,
            'user_regdate' => \Porter\Filter\UnixtimeToDate::class,
            'user_lastvisit' => \Porter\Filter\UnixtimeToDate::class,
            'DateFirstVisit' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'User',
            "select *, user_regdate as DateFirstVisit,
                    case user_avatar_type
                       when 1 then concat('phpbb/', '$px', '_', user_id,
                            substr(user_avatar from locate('.', user_avatar)))
                       when 'avatar.driver.upload' then concat('phpbb/', '$px', '_', user_id,
                            substr(user_avatar from locate('.', user_avatar)))
                       when 2 then user_avatar
                       else null end as Photo,
                    ban_userid is not null as Banned
                from :_users
                left join :_banlist bl ON (ban_userid = user_id)",
            $user_Map,
            $filters
        );
    }

    protected function ranks(): void
    {
        $map = [
            'rank_id' => 'RankID',
            'level' => 'Level',
            'rank_title' => 'Name',
            'title2' => 'Label',
            'rank_min' => 'Attributes',
        ];
        $filters = [
            'level' => function ($value, $field, $row) {
                static $level = 0;
                $level++;
                return $level;
            },
            'rank_min' => function ($value, $field, $row) {
                $result = [];
                if ($row['rank_min']) {
                    $result['Criteria']['CountPosts'] = $row['rank_min'];
                }
                if ($row['rank_special']) {
                    $result['Criteria']['Manual'] = true;
                }
                return serialize($result);
            }
        ];
        $this->export(
            'Rank',
            "select r.*, r.rank_title as title2, 0 as level
                from :_ranks r
                order by rank_special, rank_min;",
            $map,
            $filters
        );
    }

    protected function roles(): void
    {
        $role_Map = [
            'group_id' => 'RoleID',
            'group_name' => 'Name',
            'group_desc' => 'Description'
        ];
        $this->export('Role', 'select * from :_groups', $role_Map);

        // UserRoles
        $userRole_Map = [
            'user_id' => 'UserID',
            'group_id' => 'RoleID'
        ];
        $this->export(
            'UserRole',
            'select user_id, group_id from :_users
                union
                select user_id, group_id from :_user_group',
            $userRole_Map
        );
    }

    protected function signatures(): void
    {
        $map = [
            'user_id' => 'UserID',
            'name' => 'Name',
            'user_sig' => 'Value',
        ];
        $filters = [
            'user_sig' => \Porter\Filter\RemoveBbCodeUids::class,
        ];
        $this->export(
            'UserMeta',
            "select user_id, 'Plugin.Signatures.Sig' as name, user_sig, user_sig_bbcode_uid as bbcode_uid
                from :_users
                where length(user_sig) > 1
                union
                select user_id, 'Plugin.Signatures.Format', 'BBCode', null
                from :_users
                where length(user_sig) > 1",
            $map,
            $filters
        );
    }

    protected function categories(): void
    {
        $map = [
            'forum_id' => 'CategoryID',
            'forum_name' => 'Name',
            'forum_desc' => 'Description',
            'left_id' => 'Sort'
        ];
        $filters = [
            'forum_name' => \Porter\Filter\DecodeHtml::class,
        ];
        $this->export('Category', "select *, nullif(parent_id,0) as ParentCategoryID from :_forums", $map, $filters);
    }

    protected function discussions(): void
    {
        $map = [
            'topic_id' => 'DiscussionID',
            'forum_id' => 'CategoryID',
            'topic_poster' => 'InsertUserID',
            'topic_title' => 'Name',
            'Format' => 'Format',
            'topic_views' => 'CountViews',
            'topic_first_post_id' => 'FirstCommentID',
            'type' => 'Type',
            'topic_time' => 'DateInserted',
            'topic_last_post_time' => 'DateUpdated',
            'Format=BBCode',
        ];
        $filters = [
            'topic_time' => \Porter\Filter\UnixtimeToDate::class,
            'topic_last_post_time' => \Porter\Filter\UnixtimeToDate::class,
            'DateLastComment' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Discussion',
            "select t.*, t.topic_last_post_time as DateLastComment
                    case t.topic_status when 1 then 1 else 0 end as Closed,
                    case t.topic_type when 1 then 2 when 2 then 2 else 0 end as Announce,
                    case when t.poll_start > 0 then 'poll' else null end as type
                from :_topics t",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $map = [
            'post_id' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'post_text' => 'Body',
            'Format' => 'Format',
            'poster_id' => 'InsertUserID',
            'post_edit_user' => 'UpdateUserID',
            'post_time' => 'DateInserted',
            'post_edit_time' => 'DateUpdated',
            'Format=BBCode',
        ];
        $filters = [
            'post_text' => \Porter\Filter\RemoveBbCodeUids::class,
            'post_time' => \Porter\Filter\UnixtimeToDate::class,
            'post_edit_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export('Comment', "select p.* from :_posts p", $map, $filters);
    }

    protected function bookmarks(): void
    {
        $map = [
            'forum_id' => 'UserID',
            'topic_id' => 'DiscussionID',
            'mark_time' => 'DateLastViewed',
        ];
        $filter = [
            'mark_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'UserDiscussion',
            "select tt.*, if(b.topic_id is null, 0, 1) as Bookmarked
                from :_topics_track tt
                left join :_bookmarks b on b.user_id = tt.user_id and b.topic_id = tt.topic_id",
            $map,
            $filter
        );
    }

    protected function conversations(): void
    {
        $this->dbInput()->unprepared("drop table if exists z_pmto;");
        $this->dbInput()->unprepared("create table z_pmto(
                id int unsigned,
                userid int unsigned,
                primary key(id, userid) );");
        $this->dbInput()->unprepared("insert ignore into z_pmto(id, userid)
                select msg_id, author_id
                from :_privmsgs");
        $this->dbInput()->unprepared("insert ignore into z_pmto(id, userid)
                select msg_id, user_id
                from :_privmsgs_to;");
        $this->dbInput()->unprepared("insert ignore into z_pmto(id, userid)
                select msg_id, author_id
                from :_privmsgs_to");
        $this->dbInput()->unprepared("drop table if exists z_pmto2;");
        $this->dbInput()->unprepared("create table z_pmto2 (
                id int unsigned,
                userids varchar(250),
                primary key (id) );");
        $this->dbInput()->unprepared("insert ignore into z_pmto2(id, userids)
                select id, group_concat(userid order by userid)
                from z_pmto
                group by id;");
        $this->dbInput()->unprepared("drop table if exists z_pm;");
        $this->dbInput()->unprepared("create table z_pm(
                id int unsigned,
                subject varchar(255),
                subject2 varchar(255),
                userids varchar(250),
                groupid int unsigned );");
        $this->dbInput()->unprepared("insert into z_pm(id, subject, subject2, userids)
                select  pm.msg_id, pm.message_subject, t.userids
                    case
                        when pm.message_subject like 'Re: %' then trim(substring(pm.message_subject, 4))
                        else pm.message_subject
                    end as subject2
                from :_privmsgs pm
                join z_pmto2 t on t.id = pm.msg_id;");
        $this->dbInput()->unprepared("create index z_idx_pm on z_pm(id);");
        $this->dbInput()->unprepared("drop table if exists z_pmgroup;");
        $this->dbInput()->unprepared("create table z_pmgroup(
                groupid int unsigned,
                subject varchar(255),
                userids varchar(250) );");
        $this->dbInput()->unprepared("insert into z_pmgroup(groupid, subject, userids)
                select min(pm.id), pm.subject2, pm.userids
                from z_pm pm
                group by pm.subject2, pm.userids;");
        $this->dbInput()->unprepared("create index z_idx_pmgroup on z_pmgroup (subject, userids);");
        $this->dbInput()->unprepared("create index z_idx_pmgroup2 on z_pmgroup (groupid);");
        $this->dbInput()->unprepared("update z_pm pm
                join z_pmgroup g on pm.subject2 = g.subject
                    and pm.userids = g.userids
            set pm.groupid = g.groupid;");

        $map = [
            'msg_id' => 'ConversationID',
            'author_id' => 'InsertUserID',
            'RealSubject' => 'Subject',
            'message_time' => 'DateInserted',
        ];
        $filters = [
            'RealSubject' => \Porter\Filter\DecodeHtml::class,
            'message_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Conversation',
            "select pm.*, g.subject as RealSubject
                from :_privmsgs pm
                join z_pmgroup g on g.groupid = pm.msg_id",
            $map,
            $filters
        );

        // Coversation Messages.
        $conversationMessage_Map = [
            'msg_id' => 'MessageID',
            'groupid' => 'ConversationID',
            'message_text' => 'Body',
            'author_id' => 'InsertUserID',
            'message_time' => 'DateInserted',
            'Format=BBCode',
        ];
        $filters = [
            'message_time' => \Porter\Filter\UnixtimeToDate::class,
            'message_text' => \Porter\Filter\RemoveBbCodeUids::class,
        ];
        $this->export(
            'ConversationMessage',
            "select pm.*, pm2.groupid
                from :_privmsgs pm
                join z_pm pm2 on pm.msg_id = pm2.id",
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
            "select g.groupid, t.userid
                from z_pmto t
                join z_pmgroup g on g.groupid = t.id;",
            $userConversation_Map
        );

        $this->dbInput()->unprepared('drop table if exists z_pmto');
        $this->dbInput()->unprepared('drop table if exists z_pmto2;');
        $this->dbInput()->unprepared('drop table if exists z_pm;');
        $this->dbInput()->unprepared('drop table if exists z_pmgroup;');
    }

    protected function polls(): void
    {
        $map = [
            'poll_id' => 'PollID',
            'poll_title' => 'Name',
            'topic_id' => 'DiscussionID',
            'topic_time' => 'DateInserted',
            'topic_poster' => 'InsertUserID',
            'Anonymous=1',
        ];
        $filters = [
            'topic_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Poll',
            "select distinct t.*, t.topic_id as poll_id
                from :_poll_options po
                join :_topics t on po.topic_id = t.topic_id",
            $map,
            $filters
        );

        $pollOption_Map = [
            'id' => 'PollOptionID',
            'poll_option_id' => 'Sort',
            'topic_id' => 'PollID',
            'poll_option_text' => 'Body',
            'poll_option_total' => 'CountVotes',
            'topic_time' => 'DateInserted',
            'topic_poster' => 'InsertUserID',
            'Format=Html',
        ];
        $this->export(
            'PollOption',
            "select po.*, t.topic_time, t.topic_poster, po.poll_option_id * 1000000 + po.topic_id as id
                from :_poll_options po
                join :_topics t on po.topic_id = t.topic_id",
            $pollOption_Map,
            $filters
        );

        $pollVote_Map = [
            'vote_user_id' => 'UserID',
            'id' => 'PollOptionID'
        ];
        $this->export(
            'PollVote',
            "select v.*, v.poll_option_id * 1000000 + v.topic_id as id from :_poll_votes v",
            $pollVote_Map
        );
    }

    protected function attachments(): void
    {
        $map = [
            'attach_id' => 'MediaID',
            'real_filename' => 'Name',
            'poster_id' => 'InsertUserID',
            'mimetype' => 'Type',
            'filesize' => 'Size',
            'extension' => 'Extension',
            'physical_filename' => 'Path',
        ];
        $prx = $this->dbInput()->getTablePrefix();
        $query = $this->sourceQB()->from('attachments')
            ->join('topics', 'attachments.topic_id', '=', 'topics.topic_id')
            ->select('attachments.*')
            ->selectRaw('FROM_UNIXTIME(filetime) as DateInserted')
            ->selectRaw("case when {$prx}attachments.post_msg_id = {$prx}topics.topic_first_post_id
                        then 'discussion' else 'comment' end as ForeignTable")
            ->selectRaw("case when {$prx}attachments.post_msg_id = {$prx}topics.topic_first_post_id
                        then {$prx}attachments.topic_id else {$prx}attachments.post_msg_id end as ForeignID")
            ->selectRaw('concat(physical_filename, ".", extension) as TargetFullPath');
        $this->export('Media', $query, $map);
    }
}
