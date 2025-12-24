<?php

/**
 * NodeBB exporter tool
 *
 * @author  Becky Van Bussel
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class NodeBb extends Source
{
    public const SUPPORTED = [
        'name' => 'NodeBB 0.*',
        'defaultTablePrefix' => 'gdn_',
        'charsetTable' => 'post',
        'passwordHashMethod' => 'Vanilla',
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
            'Attachments' => 0,
            'Bookmarks' => 1,
            'UserNotes' => 1,
            'Tags' => 1,
            'Reactions' => 1,
        ]
    ];

    /**
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->signatures($port);

        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->polls($port);
        $this->tags($port);
        $this->bookmarks($port);
        $this->conversations($port);
        $this->reactions($port);
    }

    /**
     * @param string $name
     * @return string
     */
    public function nameToSlug($name): string
    {
        return $this->url($name);
    }

    /**
     * @param mixed $mixed
     * @return string
     */
    public function url($mixed): string
    {
        // Preliminary decoding
        $mixed = strip_tags(html_entity_decode($mixed, ENT_COMPAT, 'UTF-8'));
        $mixed = formatUrl($mixed);
        $mixed = preg_replace('`[\']`', '', $mixed);

        // Test for Unicode PCRE support
        // On non-UTF8 systems this will result in a blank string.
        $unicodeSupport = (preg_replace('`[\pP]`u', '', 'P') != '');

        // Convert punctuation, symbols, and spaces to hyphens
        if ($unicodeSupport) {
            $mixed = preg_replace('`[\pP\pS\s]`u', '-', $mixed);
        } else {
            $mixed = preg_replace('`[\s_[^\w\d]]`', '-', $mixed);
        }

        // Lowercase, no trailing or repeat hyphens
        $mixed = preg_replace('`-+`', '-', strtolower($mixed));
        $mixed = trim($mixed, '-');

        return rawurlencode($mixed);
    }

    /**
     * @param string $time
     * @return false|string|null
     */
    public function tsToDate($time): false|string|null
    {
        if (!$time) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', (int) $time / 1000);
    }

    /**
     * @param string $slug
     * @return array|string|string[]|null
     */
    public function removeNumId($slug): array|string|null
    {
        $regex = '/(\d*)\//';
        return preg_replace($regex, '', $slug);
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function roleNameFromKey($key): mixed
    {
        $regex = '/\w*:([\w|\s|-]*):/';
        preg_match($regex, $key, $matches);

        return $matches[1];
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function idFromKey($key): mixed
    {
        $regex = '/\w*:(\d*):/';
        preg_match($regex, $key, $matches);

        return $matches[1];
    }

    /**
     * @param mixed $value
     * @return int
     */
    public function makeNullZero($value): int
    {
        if (!$value) {
            return 0;
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    public function isPoll($value): ?string
    {
        if ($value) {
            return 'poll';
        }

        return null;
    }

    /**
     * @param mixed $reactions
     * @return string|null
     */
    public function serializeReactions($reactions): ?string
    {
        if ($reactions == '0:0') {
            return null;
        }
        $reactionArray = explode(':', $reactions);
        $arraynum = 1;
        if ($reactionArray[0] > 0 && $reactionArray[1] > 0) {
            $arraynum = 2;
        }
        $attributes = 'a:1:{s:5:"React";a:' . $arraynum . ':{';
        if ($reactionArray[0] > 0) {
            $attributes .= 's:2:"Up";s:' . strlen($reactionArray[0]) . ':"' . $reactionArray[0] . '";';
        }
        if ($reactionArray[1] > 0) {
            $attributes .= 's:4:"Down";s:' . strlen($reactionArray[1]) . ':"' . $reactionArray[1] . '";';
        }
        $attributes .= '}}';

        return $attributes;
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
            'uid' => 'UserID',
            'username' => 'Name',
            'password' => 'Password',
            'email' => 'Email',
            'confirmed' => 'Confirmed',
            'showemail' => 'ShowEmail',
            'joindate' => array('Column' => 'DateInserted', 'Filter' => array($this, 'tsToDate')),
            'lastonline' => array('Column' => 'DateLastActive', 'Filter' => array($this, 'tsToDate')),
            'lastposttime' => array('Column' => 'DateUpdated', 'Filter' => array($this, 'tsToDate')),
            'banned' => 'Banned',
            'admin' => 'Admin',
            'hm' => 'HashMethod'
        );
        $port->export(
            'User',
            "select uid, username, password, email, `email:confirmed` as confirmed,
                    showemail, joindate, lastonline, lastposttime, banned, 0 as admin, 'crypt' as hm
                from :_user",
            $user_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            '_num' => 'RoleID',
            '_key' => array('Column' => 'Name', 'Filter' => array($this, 'roleNameFromKey')),
            'description' => 'Description'
        );
        $port->export(
            'Role',
            "select gm._key as _key, gm._num as _num, g.description as description
                from :_group_members gm left join :_group g
                on gm._key like concat(g._key, '%')",
            $role_Map
        );

        $userRole_Map = array(
            'id' => 'RoleID',
            'members' => 'UserID'
        );
        $port->export(
            'UserRole',
            "select *, g._num as id
                from :_group_members g join :_group_members__members m
                on g._id = m._parentid",
            $userRole_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function signatures(Migration $port): void
    {
        $userMeta_Map = array(
            'uid' => 'UserID',
            'name' => 'Name',
            'signature' => 'Value'
        );
        $port->export(
            'UserMeta',
            "select uid, 'Plugin.Signatures.Sig' as name, signature
                from :_user
                where length(signature) > 1
                union
                select uid, 'Plugin.Signatures.Format', 'Markdown'
                from :_user
                where length(signature) > 1
                union
                select uid, 'Profile.Website' as name, website
                from :_user
                where length(website) > 7
                union
                select uid, 'Profile.Location' as name, location
                from :_user
                where length(location) > 1",
            $userMeta_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'cid' => 'CategoryID',
            'name' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'description' => 'Description',
            'order' => 'Sort',
            'parentCid' => 'ParentCategoryID',
            'slug' => array('Column' => 'UrlCode', 'Filter' => array($this, 'removeNumId')),
            'image' => 'Photo',
            'disabled' => 'Archived'
        );
        $port->export(
            'Category',
            "select * from :_category",
            $category_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        if (!$port->indexExists('z_idx_topic', ':_topic')) {
            $port->query("create index z_idx_topic on :_topic(mainPid);");
        }
        if (!$port->indexExists('z_idx_post', ':_post')) {
            $port->query("create index z_idx_post on :_post(pid);");
        }
        if (!$port->indexExists('z_idx_poll', ':_poll')) {
            $port->query("create index z_idx_poll on :_poll(tid);");
        }

        $port->query("drop table if exists z_discussionids;");
        $port->query(
            "

            create table z_discussionids (
                tid int unsigned,
                primary key(tid)
            );

        "
        );
        $port->query(
            "insert ignore z_discussionids (
                tid
            )
            select mainPid
            from :_topic
            where mainPid is not null
            and deleted != 1;"
        );

        $port->query("drop table if exists z_reactiontotalsupvote;");
        $port->query(
            "create table z_reactiontotalsupvote (
                value varchar(50),
                total int,
                primary key (value)
            );"
        );

        $port->query("drop table if exists z_reactiontotalsdownvote;");
        $port->query(
            "create table z_reactiontotalsdownvote (
                value varchar(50),
                total int,
                primary key (value)
            );"
        );

        $port->query("drop table if exists z_reactiontotals;");
        $port->query(
            "create table z_reactiontotals (
              value varchar(50),
              upvote int,
              downvote int,
              primary key (value)
            );"
        );

        $port->query(
            "insert z_reactiontotalsupvote
            select value, count(*) as totals
            from :_uid_upvote
            group by value;"
        );

        $port->query(
            " insert z_reactiontotalsdownvote
            select value, count(*) as totals
            from :_uid_downvote
            group by value;"
        );

        $port->query(
            "insert z_reactiontotals
            select *
            from (
                select u.value, u.total as up, d.total as down
                from z_reactiontotalsupvote u
                left join z_reactiontotalsdownvote d
                on u.value = d.value
                union
                select d.value, u.total as up, d.total as down
                from z_reactiontotalsdownvote d
                left join z_reactiontotalsupvote u
                on u.value = d.value
            ) as reactions"
        );

        //Discussions
        $discussion_Map = array(
            'tid' => 'DiscussionID',
            'cid' => 'CategoryID',
            'title' => 'Name',
            'content' => 'Body',
            'uid' => 'InsertUserID',
            'locked' => 'Closed',
            'pinned' => 'Announce',
            'timestamp' => array('Column' => 'DateInserted', 'Filter' => array($this, 'tsToDate')),
            'edited' => array('Column' => 'DateUpdated', 'Filter' => array($this, 'tsToDate')),
            'editor' => 'UpdateUserID',
            'viewcount' => 'CountViews',
            'format' => 'Format',
            'votes' => 'Score',
            'attributes' => array('Column' => 'Attributes', 'Filter' => array($this, 'serializeReactions')),
            'poll' => array('Column' => 'Type', 'Filter' => array($this, 'isPoll'))
        );

        $port->export(
            'Discussion',
            "select p.tid, cid, title, content, p.uid, locked, pinned, p.timestamp,
                    p.edited, p.editor, viewcount, votes, poll._id as poll, 'Markdown' as format,
                    concat(ifnull(u.total, 0), ':', ifnull(d.total, 0)) as attributes
                from :_topic t
                left join :_post p
                on t.mainPid = p.pid
                left join z_reactiontotalsupvote u
                on u.value = t.mainPid
                left join z_reactiontotalsdownvote d
                on d.value = t.mainPid
                left join :_poll poll
                on p.tid = poll.tid
                where t.deleted != 1",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $port->query("drop table if exists z_comments;");
        $port->query(
            "create table z_comments (
                pid int,
                content text,
                uid varchar(255),
                tid varchar(255),
                timestamp double,
                edited varchar(255),
                editor varchar(255),
                votes int,
                upvote int,
                downvote int,
                primary key(pid)
            );"
        );

        $port->query(
            "insert ignore z_comments (
                pid,
                content,
                uid,
                tid,
                timestamp,
                edited,
                editor,
                votes
            )
            select p.pid, p.content, p.uid, p.tid, p.timestamp, p.edited, p.editor, p.votes
            from :_post p
            left join z_discussionids t
            on t.tid = p.pid
            where p.deleted != 1 and t.tid is null;"
        );

        $port->query(
            "update z_comments as c
            join z_reactiontotals r
            on r.value = c.pid
            set c.upvote = r.upvote, c.downvote = r.downvote;"
        );

        // Comments
        $comment_Map = array(
            'content' => 'Body',
            'uid' => 'InsertUserID',
            'tid' => 'DiscussionID',
            'timestamp' => array('Column' => 'DateInserted', 'Filter' => array($this, 'tsToDate')),
            'edited' => array('Column' => 'DateUpdated', 'Filter' => array($this, 'tsToDate')),
            'editor' => 'UpdateUserID',
            'votes' => 'Score',
            'format' => 'Format',
            'attributes' => array('Column' => 'Attributes', 'Filter' => array($this, 'serializeReactions'))
        );

        $port->export(
            'Comment',
            "select content, uid, tid, timestamp, edited, editor, votes, 'Markdown' as format,
                    concat(ifnull(upvote, 0), ':', ifnull(downvote, 0)) as attributes
                from z_comments",
            $comment_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function polls(Migration $port): void
    {
        $poll_Map = array(
            'pollid' => 'PollID',
            'title' => 'Name',
            'tid' => 'DiscussionID',
            'votecount' => 'CountVotes',
            'uid' => 'InsertUserID',
            'timestamp' => array('Column' => 'DateInserted', 'Filter' => array($this, 'tsToDate'))
        );
        $port->export(
            'Poll',
            "select *
                from :_poll p left join :_poll_settings ps
                on ps._key like concat(p._key, ':', '%')",
            $poll_Map
        );

        $pollOption_Map = array(
            '_num' => 'PollOptionID',
            '_key' => array('Column' => 'PollID', 'Filter' => array($this, 'idFromKey')),
            'title' => 'Body',
            'sort' => 'Sort',
            'votecount' => array('Column' => 'CountVotes', 'Filter' => array($this, 'makeNullZero')),
            'format' => 'Format'
        );
        $port->export(
            'PollOption',
            "select _num, _key, title, id+1 as sort, votecount, 'Html' as format
                from :_poll_options
                where title is not null",
            $pollOption_Map
        );

        $pollVote_Map = array(
            'userid' => 'UserID',
            'poll_option_id' => 'PollOptionID'
        );
        $port->export(
            'PollVote',
            "select povm.members as userid, po._num as poll_option_id
                from :_poll_options_votes__members povm
                left join :_poll_options_votes pov
                on povm._parentid = pov._id
                left join :_poll_options po
                on pov._key like concat(po._key, ':', '%')
                where po.title is not null",
            $pollVote_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function tags(Migration $port): void
    {
        if (!$port->indexExists('z_idx_topic_key', ':_topic')) {
            $port->query("create index z_idx_topic_key on :_topic (_key);");
        }

        $tag_Map = array(
            'slug' => array('Column' => 'Name', 'Filter' => array($this, 'nameToSlug')),
            'fullname' => 'FullName',
            'count' => 'CountDiscussions',
            'tagid' => 'TagID',
            'cid' => 'CategoryID',
            'type' => 'Type',
            'timestamp' => array('Column' => 'DateInserted', 'Filter' => array($this, 'tsToDate')),
            'uid' => 'InsertUserID'
        );

        $port->query("set @rownr=1000;");

        $port->export(
            'Tag',
            "select @rownr:=@rownr+1 as tagid, members as fullname, members as slug,
                    '' as type, count, timestamp, uid, cid
                from (
                    select members, count(*) as count, _parentid
                    from :_topic_tags__members
                    group by members
                ) as tags
                join :_topic_tags tt
                on tt._id = _parentid
                left join :_topic t
                on substring(tt._key, 1, length(tt._key) - 5) = t._key",
            $tag_Map
        );

        $tagDiscussion_Map = array(
            'tagid' => 'TagID',
            'tid' => 'DiscussionID',
            'cid' => 'CategoryID',
            'timestamp' => array('Column' => 'DateInserted', 'Filter' => array($this, 'tsToDate'))
        );

        $port->query("set @rownr=1000;");

        $port->export(
            'TagDiscussion',
            "select tagid, cid, tid, timestamp
                from :_topic_tags__members two
                join (
                    select @rownr:=@rownr+1 as tagid, members as fullname, members as slug, count
                    from (
                        select members, count(*) as count
                        from :_topic_tags__members
                        group by members
                    ) as tags
                ) as tagids
                on two.members = tagids.fullname
                join :_topic_tags tt
                on tt._id = _parentid
                left join :_topic t
                on substring(tt._key, 1, length(tt._key) - 5) = t._key",
            $tagDiscussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        if (!$port->indexExists('z_idx_message_key', ':_message')) {
            $port->query("create index z_idx_message_key on :_message(_key);");
        }
        $port->query("drop table if exists z_pmto;");
        $port->query(
            "create table z_pmto (
                pmid int unsigned,
                userid int,
                groupid int,
                primary key(pmid, userid)
            );"
        );
        $port->query(
            "insert ignore z_pmto (
                pmid,
                userid
            )
            select substring_index(_key, ':', -1), fromuid
            from :_message;"
        );
        $port->query(
            "insert ignore z_pmto (
                pmid,
                userid
            )
            select substring_index(_key, ':', -1), touid
            from :_message;"
        );

        $port->query("drop table if exists z_pmto2;");
        $port->query(
            "create table z_pmto2 (
                pmid int unsigned,
                userids varchar(250),
                groupid int unsigned,
                primary key (pmid)
            );"
        );
        $port->query(
            "replace z_pmto2 (
                pmid,
                userids
            )
            select pmid, group_concat(userid order by userid)
            from z_pmto
            group by pmid;"
        );

        $port->query("drop table if exists z_pmgroup;");
        $port->query(
            "create table z_pmgroup (
                userids varchar(250),
                groupid varchar(255),
                firstmessageid int,
                lastmessageid int,
                countmessages int,
                primary key (userids, groupid)
            );"
        );
        $port->query(
            "insert z_pmgroup
            select userids, concat('message:', min(pmid)), min(pmid), max(pmid), count(*)
            from z_pmto2
            group by userids;"
        );

        $port->query(
            "update z_pmto2 as p
            left join z_pmgroup g
            on p.userids = g.userids
            set p.groupid = g.firstmessageid;"
        );

        $port->query(
            "update z_pmto as p
            left join z_pmto2 p2
            on p.pmid = p2.pmid
            set p.groupid = p2.groupid;"
        );

        $port->query("create index z_idx_pmto_cid on z_pmto(groupid);");
        $port->query("create index z_idx_pmgroup_cid on z_pmgroup(firstmessageid);");

        $conversation_Map = array(
            'conversationid' => 'ConversationID',
            'firstmessageid' => 'FirstMessageID',
            'lastmessageid' => 'LastMessageID',
            'countparticipants' => 'CountParticipants',
            'countmessages' => 'CountMessages'
        );
        $port->export(
            'Conversation',
            "select *, firstmessageid as conversationid, 2 as countparticipants
            from z_pmgroup
            left join :_message
            on groupid = _key;",
            $conversation_Map
        );

        $conversationMessage_Map = array(
            'messageid' => 'MessageID',
            'conversationid' => 'ConversationID',
            'content' => 'Body',
            'format' => 'Format',
            'fromuid' => 'InsertUserID',
            'timestamp' => array('Column' => 'DateInserted', 'Filter' => array($this, 'tsToDate'))
        );
        $port->export(
            'ConversationMessage',
            "select groupid as conversationid, pmid as messageid, content, 'Text' as format, fromuid, timestamp
                from z_pmto2
                left join :_message
                on concat('message:', pmid) = _key",
            $conversationMessage_Map
        );

        $userConversationMap = array(
            'conversationid' => 'ConversationID',
            'userid' => 'UserID',
            'lastmessageid' => 'LastMessageID'
        );
        $port->export(
            'UserConversation',
            "select p.groupid as conversationid, userid, lastmessageid
                from z_pmto p
                left join z_pmgroup
                on firstmessageid = p.groupid;",
            $userConversationMap
        );
    }

    /**
     * @param Migration $port
     */
    protected function bookmarks(Migration $port): void
    {
        $userDiscussion_Map = array(
            'members' => 'UserID',
            '_key' => array('Column' => 'DiscussionID', 'Filter' => array($this, 'idFromKey')),
            'bookmarked' => 'Bookmarked'
        );

        $port->export(
            'UserDiscussion',
            "select members, _key, 1 as bookmarked
                from :_tid_followers__members
                left join :_tid_followers
                on _parentid = _id",
            $userDiscussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function reactions(Migration $port): void
    {
        if (!$port->indexExists('z_idx_topic_mainpid', ':_topic')) {
            $port->query("create index z_idx_topic_mainpid on :_topic(mainPid);");
        }
        if (!$port->indexExists('z_idx_uid_downvote', ':_uid_downvote')) {
            $port->query("create index z_idx_uid_downvote on :_uid_downvote(value);");
        }
        if (!$port->indexExists('z_idx_uid_upvote', ':_uid_upvote')) {
            $port->query("create index z_idx_uid_upvote on :_uid_upvote(value);");
        }

        $userTag_Map = array(
            'tagid' => 'TagID',
            'recordtype' => 'RecordType',
            '_key' => array('Column' => 'UserID', 'Filter' => array($this, 'idFromKey')),
            'value' => 'RecordID',
            'score' => array('Column' => 'DateInserted', 'Filter' => array($this, 'tsToDate')),
            'total' => 'Total'
        );
        $port->export(
            'UserTag',
            "select 11 as tagid, 'Discussion' as recordtype, u._key, u.value, score, total
                from :_uid_upvote u
                left join z_discussionids t
                on u.value = t.tid
                left join z_reactiontotalsupvote r
                on  r.value = u.value
                where u._key != 'uid:NaN:upvote'
                and t.tid is not null
                union
                select 11 as tagid, 'Comment' as recordtype, u._key, u.value, score, total
                from :_uid_upvote u
                left join z_discussionids t
                on u.value = t.tid
                left join z_reactiontotalsupvote r
                on  r.value = u.value
                where u._key != 'uid:NaN:upvote'
                and t.tid is null
                union
                select 10 as tagid, 'Discussion' as recordtype, u._key, u.value, score, total
                from :_uid_downvote u
                left join z_discussionids t
                on u.value = t.tid
                left join z_reactiontotalsdownvote r
                on  r.value = u.value
                where u._key != 'uid:NaN:downvote'
                and t.tid is not null
                union
                select 10 as tagid, 'Comment' as recordtype, u._key, u.value, score, total
                from :_uid_downvote u
                left join z_discussionids t
                on u.value = t.tid
                left join z_reactiontotalsdownvote r
                on  r.value = u.value
                where u._key != 'uid:NaN:downvote'
                and t.tid is null",
            $userTag_Map
        );
    }
}
