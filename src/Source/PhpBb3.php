<?php

/**
 * phpBB exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;
use Porter\Migration;

class PhpBb3 extends Source
{
    public const SUPPORTED = [
        'name' => 'phpBB 3',
        'defaultTablePrefix' => 'phpbb_',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'phpBB',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 1,
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 1,
            'Signatures' => 1,
            'Attachments' => 1,
            'Bookmarks' => 1,
            'Badges' => 0,
            'UserNotes' => 1,
            'Ranks' => 1,
        ]
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = array(
        'users' => array(
            'user_id',
            'username',
            'user_password',
            'user_email',
            'user_timezone',
            'user_posts',
            'user_regdate',
            'user_lastvisit',
            'user_regdate'
        ),
        'groups' => array('group_id', 'group_name', 'group_desc'),
        'user_group' => array('user_id', 'group_id'),
        'forums' => array('forum_id', 'forum_name', 'forum_desc', 'left_id', 'parent_id'),
        'topics' => array(
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
        ),
        'posts' => array(
            'post_id',
            'topic_id',
            'post_text',
            'poster_id',
            'post_edit_user',
            'post_time',
            'post_edit_time'
        ),
        'bookmarks' => array('user_id', 'topic_id')
    );

    /**
     * Forum-specific export format.
     *
     * @param Migration $port
     */
    public function run(?Migration $port = null): void
    {
        $this->users($port);
        $this->roles($port);
        $this->userNotes($port);
        $this->ranks($port);
        $this->signatures($port);

        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->bookmarks($port);
        $this->polls($port);
        $this->conversations($port);
        $this->attachments($port);
    }

    /**
     * @param Migration $port
     */
    protected function userNotes(Migration $port): void
    {
        $corruptedRecords = [];

        // User notes.
        $userNote_Map = array(
            'log_id' => array('Column' => 'UserNoteID', 'Type' => 'int'),
            'user_id' => array('Column' => 'InsertUserID', 'Type' => 'int'),
            'reportee_id' => array('Column' => 'UserID', 'Type' => 'int'),
            'log_ip' => array('Column' => 'InsertIPAddress', 'Type' => 'varchar(15)'),
            'log_time' => array('Column' => 'DateInserted', 'Type' => 'datetime', 'Filter' => 'timestampToDate'),
            'log_operation' => array(
                'Column' => 'Type',
                'Type' => 'varchar(10)',
                'Filter' => function ($value) {
                    switch (strtoupper($value)) {
                        case 'LOG_USER_WARNING_BODY':
                            return 'warning';
                        default:
                            return 'note';
                    }
                }
            ),
            'format' => array('Column' => 'Format', 'Type' => 'varchar(20)'),
            'log_data' => array(
                'Column' => 'Body',
                'Type' => 'text',
                'Filter' => function ($value, $field, $row) use (&$corruptedRecords) {
                    $unserializedValue = @unserialize($value);

                    if (!$unserializedValue || !is_array($unserializedValue)) {
                        $corruptedRecords[] = $row['log_id'];
                        return '';
                    }
                    return array_pop($unserializedValue);
                }
            )
        );
        $port->export(
            'UserNote',
            "select l.*, 'Text' as format
                from :_log l
                where reportee_id > 0
                    and log_operation in ('LOG_USER_GENERAL', 'LOG_USER_WARNING_BODY')",
            $userNote_Map
        );

        if (count($corruptedRecords) > 0) {
            Log::Comment("Corrupted records found in \"_log\" table while exporting to UserNote\n"
                . print_r($corruptedRecords, true));
        }
    }

    public static function entityDecode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param mixed $r
     * @param string $field
     * @param array $row
     * @return array|string|string[]|null
     */
    public function removeBBCodeUIDs($r, $field, $row): array|string|null
    {
        if (!$r) {
            return $r;
        }

        $UID = trim($row['bbcode_uid']);
        //      $UID = '2zp03s9s';
        if ($UID) {
            $r = preg_replace("`((?::[a-zA-Z])?:$UID)`", '', $r);
        }

        // Remove smilies.
        $r = preg_replace('#<!\-\- s(.*?) \-\-><img src="\{SMILIES_PATH\}\/.*? \/><!\-\- s\1 \-\->#', '\1', $r);
        // Remove links.
        $regex = '`<!-- [a-z] --><a\s+class="[^"]+"\s+href="([^"]+)">([^<]+)</a><!-- [a-z] -->`';
        $r = preg_replace($regex, '[url=$1]$2[/url]', $r);

        // Allow mailto: links w/o a class.
        $regex = '`<!-- [a-z] --><a\s+href="mailto:([^"]+)">([^<]+)</a><!-- [a-z] -->`i';
        $r = preg_replace($regex, '[url=$1]$2[/url]', $r);

        $r = str_replace(
            array('&quot;', '&#39;', '&#58;', 'Â', '&#46;', '&amp;'),
            array('"', "'", ':', '', '.', '&'),
            $r
        );

        return $r;
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     *
     * @param  string $value Current value
     * @param  string $field Current field
     * @param  array  $row   Contents of the current record.
     * @return string|null Return the supplied value if the record's file is an image. Return null otherwise
     *@see    Migration::writeTableToFile
     *
     */
    public function filterThumbnailData($value, $field, $row): ?string
    {
        if (strpos(strtolower($row['mimetype']), 'image/') === 0) {
            return $value;
        } else {
            return null;
        }
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        // Grab the avatar salt.
        //$data = $port->get("select config_value from :_config where config_name = 'avatar_salt'");
        $data = $port->dbInput()->table('config')
            ->select(['config_value'])
            ->where('config_name', '=', 'avatar_salt')
            ->first();
        $px = $data->config_value ?? '';

        $cdn = ''; //$this->param('cdn', '');

        $user_Map = array(
            'user_id' => 'UserID',
            'username' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'user_password' => 'Password',
            'user_email' => 'Email',
            //'user_timezone' => 'HourOffset',
            'user_posts' => array('Column' => 'CountComments', 'Type' => 'int'),
            'photo' => 'Photo',
            'user_rank' => 'RankID',
            'user_ip' => 'LastIPAddress'
        );
        $port->export(
            'User',
            "select *,
                    case user_avatar_type
                       when 1 then concat('$cdn', 'phpbb/', '$px', '_', user_id,
                            substr(user_avatar from locate('.', user_avatar)))
                       when 'avatar.driver.upload' then concat('$cdn', 'phpbb/', '$px', '_', user_id,
                            substr(user_avatar from locate('.', user_avatar)))
                       when 2 then user_avatar
                       else null end as photo,
                    FROM_UNIXTIME(nullif(user_regdate, 0)) as DateFirstVisit,
                    FROM_UNIXTIME(nullif(user_lastvisit, 0)) as DateLastActive,
                    FROM_UNIXTIME(nullif(user_regdate, 0)) as DateInserted,
                    ban_userid is not null as Banned
                from :_users
                    left join :_banlist bl ON (ban_userid = user_id)",
            $user_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function ranks(Migration $port): void
    {
        $rank_Map = array(
            'rank_id' => 'RankID',
            'level' => array(
                'Column' => 'Level',
                'Filter' => function ($value) {
                    static $level = 0;
                    $level++;

                    return $level;
                }
            ),
            'rank_title' => 'Name',
            'title2' => 'Label',
            'rank_min' => array(
                'Column' => 'Attributes',
                'Filter' => function ($value, $field, $row) {
                    $result = array();

                    if ($row['rank_min']) {
                        $result['Criteria']['CountPosts'] = $row['rank_min'];
                    }

                    if ($row['rank_special']) {
                        $result['Criteria']['Manual'] = true;
                    }

                    return serialize($result);
                }
            )
        );
        $port->export(
            'Rank',
            "select
                    r.*,
                    r.rank_title as title2,
                    0 as level
                from :_ranks r
                order by
                    rank_special,
                    rank_min;",
            $rank_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'group_id' => 'RoleID',
            'group_name' => 'Name',
            'group_desc' => 'Description'
        );
        $port->export('Role', 'select * from :_groups', $role_Map);

        // UserRoles
        $userRole_Map = array(
            'user_id' => 'UserID',
            'group_id' => 'RoleID'
        );
        $port->export(
            'UserRole',
            'select user_id, group_id from :_users
                union
                select user_id, group_id from :_user_group',
            $userRole_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function signatures(Migration $port): void
    {
        $userMeta_Map = array(
            'user_id' => 'UserID',
            'name' => 'Name',
            'user_sig' => array('Column' => 'Value', 'Filter' => array($this, 'removeBBCodeUIDs'))
        );
        $port->export(
            'UserMeta',
            "select
                    user_id,
                    'Plugin.Signatures.Sig' as name,
                    user_sig,
                    user_sig_bbcode_uid as bbcode_uid
                from :_users
                where length(user_sig) > 1
                union
                select
                    user_id,
                    'Plugin.Signatures.Format',
                    'BBCode',
                    null
                from :_users
                where length(user_sig) > 1",
            $userMeta_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'forum_id' => 'CategoryID',
            'forum_name' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'forum_desc' => 'Description',
            'left_id' => 'Sort'
        );
        $port->export(
            'Category',
            "select *,
                    nullif(parent_id,0) as ParentCategoryID
                from :_forums",
            $category_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'topic_id' => 'DiscussionID',
            'forum_id' => 'CategoryID',
            'topic_poster' => 'InsertUserID',
            'topic_title' => 'Name',
            'Format' => 'Format',
            'topic_views' => 'CountViews',
            'topic_first_post_id' => array('Column' => 'FirstCommentID', 'Type' => 'int'),
            'type' => 'Type'
        );
        $port->export(
            'Discussion',
            "select t.*,
                    'BBCode' as Format,
                    case t.topic_status when 1 then 1 else 0 end as Closed,
                    case t.topic_type when 1 then 2 when 2 then 2 else 0 end as Announce,
                    case when t.poll_start > 0 then 'poll' else null end as type,
                    FROM_UNIXTIME(t.topic_time) as DateInserted,
                    FROM_UNIXTIME(t.topic_last_post_time) as DateUpdated,
                    FROM_UNIXTIME(t.topic_last_post_time) as DateLastComment
                from :_topics t",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'post_id' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'post_text' => array('Column' => 'Body', 'Filter' => array($this, 'removeBBCodeUIDs')),
            'Format' => 'Format',
            'poster_id' => 'InsertUserID',
            'poster_ip' => array('Column' => 'InsertIPAddress', 'Filter' => 'forceIP4'),
            'post_edit_user' => 'UpdateUserID'
        );
        $port->export(
            'Comment',
            "select p.*,
                    'BBCode' as Format,
                    FROM_UNIXTIME(p.post_time) as DateInserted,
                    FROM_UNIXTIME(nullif(p.post_edit_time,0)) as DateUpdated
                from :_posts p",
            $comment_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function bookmarks(Migration $port): void
    {
        $port->export(
            'UserDiscussion',
            "select
                    tt.user_id as UserID,
                    tt.topic_id as DiscussionID,
                     FROM_UNIXTIME(tt.mark_time) as DateLastViewed,
                    if(b.topic_id is null, 0, 1) as Bookmarked
                from :_topics_track tt
                left join :_bookmarks b on b.user_id = tt.user_id and b.topic_id = tt.topic_id"
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        $port->query("drop table if exists z_pmto;");

        $port->query(
            "create table z_pmto(
                id int unsigned,
                userid int unsigned,
                primary key(id, userid)
            );"
        );

        $port->query(
            "insert ignore into z_pmto(id, userid)
                select
                    msg_id,
                    author_id
                from :_privmsgs"
        );

        $port->query(
            "insert ignore into z_pmto(id, userid)
                select
                    msg_id,
                    user_id
                from :_privmsgs_to;"
        );

        $port->query(
            "insert ignore into z_pmto(id, userid)
                select
                    msg_id,
                    author_id
                from :_privmsgs_to"
        );

        $port->query("drop table if exists z_pmto2;");

        $port->query(
            "create table z_pmto2 (
                id int unsigned,
                userids varchar(250),
                primary key (id)
            );"
        );

        $port->query(
            "insert ignore into z_pmto2(id, userids)
                select
                    id,
                    group_concat(userid order by userid)
                from z_pmto
                group by id;"
        );

        $port->query("drop table if exists z_pm;");

        $port->query(
            "create table z_pm(
                id int unsigned,
                subject varchar(255),
                subject2 varchar(255),
                userids varchar(250),
                groupid int unsigned
            );"
        );

        $port->query(
            "insert into z_pm(id, subject, subject2, userids)
                select
                    pm.msg_id,
                    pm.message_subject,
                    case
                        when pm.message_subject like 'Re: %' then trim(substring(pm.message_subject, 4))
                        else pm.message_subject
                    end as subject2,
                    t.userids
                from :_privmsgs pm
                    join z_pmto2 t on t.id = pm.msg_id;"
        );

        $port->query("create index z_idx_pm on z_pm(id);");

        $port->query("drop table if exists z_pmgroup;");

        $port->query(
            "create table z_pmgroup(
                groupid int unsigned,
                subject varchar(255),
                userids varchar(250)
            );"
        );

        $port->query(
            "insert into z_pmgroup(groupid, subject, userids)
                select
                    min(pm.id),
                    pm.subject2,
                    pm.userids
                from z_pm pm
                group by
                    pm.subject2, pm.userids;"
        );

        $port->query("create index z_idx_pmgroup on z_pmgroup (subject, userids);");
        $port->query("create index z_idx_pmgroup2 on z_pmgroup (groupid);");

        $port->query(
            "update z_pm pm
                join z_pmgroup g on pm.subject2 = g.subject
                    and pm.userids = g.userids
            set pm.groupid = g.groupid;"
        );

        $conversation_Map = array(
            'msg_id' => 'ConversationID',
            'author_id' => 'InsertUserID',
            'RealSubject' => array(
                'Column' => 'Subject',
                'Type' => 'varchar(250)',
                'Filter' => array($this, 'EntityDecode')
            )
        );
        $port->export(
            'Conversation',
            "select pm.*,
                    g.subject as RealSubject,
                    from_unixtime(pm.message_time) as DateInserted
                from :_privmsgs pm
                    join z_pmgroup g on g.groupid = pm.msg_id",
            $conversation_Map
        );

        // Coversation Messages.
        $conversationMessage_Map = array(
            'msg_id' => 'MessageID',
            'groupid' => 'ConversationID',
            'message_text' => array('Column' => 'Body', 'Filter' => array($this, 'removeBBCodeUIDs')),
            'author_id' => 'InsertUserID'
        );
        $port->export(
            'ConversationMessage',
            "select pm.*,
                    pm2.groupid,
                    'BBCode' as Format,
                    FROM_UNIXTIME(pm.message_time) as DateInserted
                from :_privmsgs pm
                    join z_pm pm2 on pm.msg_id = pm2.id",
            $conversationMessage_Map
        );

        // User Conversation.
        $userConversation_Map = array(
            'userid' => 'UserID',
            'groupid' => 'ConversationID'
        );
        $port->export(
            'UserConversation',
            "select g.groupid, t.userid
                from z_pmto t
                join z_pmgroup g on g.groupid = t.id;",
            $userConversation_Map
        );

        $port->query('drop table if exists z_pmto');
        $port->query('drop table if exists z_pmto2;');
        $port->query('drop table if exists z_pm;');
        $port->query('drop table if exists z_pmgroup;');
    }

    /**
     * @param Migration $port
     */
    protected function polls(Migration $port): void
    {
        $poll_Map = array(
            'poll_id' => 'PollID',
            'poll_title' => 'Name',
            'topic_id' => 'DiscussionID',
            'topic_time' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'topic_poster' => 'InsertUserID',
            'anonymous' => 'Anonymous'
        );
        $port->export(
            'Poll',
            "select distinct
                    t.*,
                    t.topic_id as poll_id,
                    1 as anonymous
                from :_poll_options po
                    join :_topics t on po.topic_id = t.topic_id",
            $poll_Map
        );

        $pollOption_Map = array(
            'id' => 'PollOptionID',
            'poll_option_id' => 'Sort',
            'topic_id' => 'PollID',
            'poll_option_text' => 'Body',
            'format' => 'Format',
            'poll_option_total' => 'CountVotes',
            'topic_time' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'topic_poster' => 'InsertUserID'
        );
        $port->export(
            'PollOption',
            "select po.*,
                    po.poll_option_id * 1000000 + po.topic_id as id,
                    'Html' as format,
                    t.topic_time,
                    t.topic_poster
                from :_poll_options po
                    join :_topics t on po.topic_id = t.topic_id",
            $pollOption_Map
        );

        $pollVote_Map = array(
            'vote_user_id' => 'UserID',
            'id' => 'PollOptionID'
        );
        $port->export(
            'PollVote',
            "select v.*,
                    v.poll_option_id * 1000000 + v.topic_id as id
                from :_poll_votes v",
            $pollVote_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function attachments(Migration $port): void
    {
        $cdn = ''; //$this->param('cdn', '');
        $media_Map = array(
            'attach_id' => 'MediaID',
            'real_filename' => 'Name',
            'thumb_path' => array('Column' => 'ThumbPath', 'Filter' => array($this, 'filterThumbnailData')),
            'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
            'post_id' => 'InsertUserID',
            'mimetype' => 'Type',
            'filesize' => 'Size',
        );
        $port->export(
            'Media',
            "select a.*,
                    case when a.post_msg_id = t.topic_first_post_id
                        then 'discussion' else 'comment' end as ForeignTable,
                    case when a.post_msg_id = t.topic_first_post_id
                        then a.topic_id else a.post_msg_id end as ForeignID,
                    concat('$cdn','FileUpload/', a.physical_filename, '.', a.extension) as Path,
                    concat('$cdn','FileUpload/', a.physical_filename, '.', a.extension) as thumb_path,
                    128 as thumb_width,
                    FROM_UNIXTIME(a.filetime) as DateInserted
                from :_attachments a
                    join :_topics t on a.topic_id = t.topic_id",
            $media_Map
        );
    }

    /**
     * Add file extension to hashed phpBB3 attachment filenames.
     *
     * @param Migration $port
     * @param string $directory
     */
    protected function exportBlobs(Migration $port, string $directory): void
    {
        // Select attachments
        $result = $port->query("select physical_filename as name, extension as ext from phpbb_attachments");

        // Iterate thru files based on database results and rename.
        $renamed = $failed = 0;
        while ($row = $result->nextResultRow()) {
            if (file_exists($directory . $row['name'])) {
                rename($directory . $row['name'], $directory . $row['name'] . '.' . $row['ext']);
                $renamed++;

                if (file_exists($directory . 'thumb_' . $row['name'])) {
                    rename(
                        $directory . 'thumb_' . $row['name'],
                        $directory . 'thumb_' . $row['name'] . '.' . $row['ext']
                    );
                }
            } else {
                $failed++;
            }
        }
        Log::comment('Renamed ' . $renamed . ' files. ' . $failed . 'failures.');
    }
}
