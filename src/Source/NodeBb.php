<?php

/**
 * NodeBB exporter tool
 *
 * @author  Becky Van Bussel
 */

namespace Porter\Source;

use Porter\Source;

class NodeBb extends Source
{
    public const array INFO = [
        'name' => 'NodeBB 0.*',
        'defaultTablePrefix' => 'gdn_',
        'charsetTable' => 'post',
        'passwordHashMethod' => 'Vanilla',
    ];

    protected function users(): void
    {
        $map = [
            'uid' => 'UserID',
            'username' => 'Name',
            'password' => 'Password',
            'email' => 'Email',
            'confirmed' => 'Confirmed',
            'showemail' => 'ShowEmail',
            'joindate' => 'DateInserted',
            'lastonline' => 'DateLastActive',
            'lastposttime' => 'DateUpdated',
            'banned' => 'Banned',
            'HashMethod=crypt'
        ];
        $filters = [
            'joindate' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
            'lastonline' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
            'lastposttime' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
        ];
        $this->export(
            'User',
            "select uid, username, password, email, `email:confirmed` as confirmed,
                    showemail, joindate, lastonline, lastposttime, banned
                from :_user",
            $map,
            $filters
        );
    }

    protected function roles(): void
    {
        $map = [
            '_num' => 'RoleID',
            '_key' => 'Name',
            'description' => 'Description',
        ];
        $filters = [
            '_key' => \Porter\Filter\ExtractColonDelimWord::class,
        ];
        $this->export(
            'Role',
            "select gm._key as _key, gm._num as _num, g.description as description
                from :_group_members gm left join :_group g
                on gm._key like concat(g._key, '%')",
            $map,
            $filters
        );

        $userRole_Map = [
            'id' => 'RoleID',
            'members' => 'UserID'
        ];
        $this->export(
            'UserRole',
            "select *, g._num as id
                from :_group_members g join :_group_members__members m
                on g._id = m._parentid",
            $userRole_Map
        );
    }

    protected function signatures(): void
    {
        $userMeta_Map = [
            'uid' => 'UserID',
            'name' => 'Name',
            'signature' => 'Value'
        ];
        $this->export(
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

    protected function categories(): void
    {
        $map = [
            'cid' => 'CategoryID',
            'name' => 'Name',
            'description' => 'Description',
            'order' => 'Sort',
            'parentCid' => 'ParentCategoryID',
            'slug' => 'UrlCode',
            'image' => 'Photo',
            'disabled' => 'Archived'
        ];
        $filters = [
            'name' => \Porter\Filter\DecodeHtml::class,
            'slug' => \Porter\Filter\RemoveNumber::class,
        ];
        $this->export('Category', "select * from :_category", $map, $filters);
    }

    protected function discussions(): void
    {
        if (!$this->indexExists('z_idx_topic', ':_topic')) {
            $this->dbInput()->unprepared("create index z_idx_topic on :_topic(mainPid);");
        }
        if (!$this->indexExists('z_idx_post', ':_post')) {
            $this->dbInput()->unprepared("create index z_idx_post on :_post(pid);");
        }
        if (!$this->indexExists('z_idx_poll', ':_poll')) {
            $this->dbInput()->unprepared("create index z_idx_poll on :_poll(tid);");
        }

        $this->dbInput()->unprepared("drop table if exists z_discussionids;");
        $this->dbInput()->unprepared("create table z_discussionids (tid int unsigned, primary key(tid));");
        $this->dbInput()->unprepared("insert ignore z_discussionids (tid)
            select mainPid from :_topic
            where mainPid is not null and deleted != 1;");

        $this->dbInput()->unprepared("drop table if exists z_reactiontotalsupvote;");
        $this->dbInput()->unprepared("create table z_reactiontotalsupvote (
                value varchar(50), total int, primary key (value));");

        $this->dbInput()->unprepared("drop table if exists z_reactiontotalsdownvote;");
        $this->dbInput()->unprepared("create table z_reactiontotalsdownvote (
                value varchar(50), total int, primary key (value));");

        $this->dbInput()->unprepared("drop table if exists z_reactiontotals;");
        $this->dbInput()->unprepared("create table z_reactiontotals (
              value varchar(50), upvote int, downvote int, primary key (value));");

        $this->dbInput()->unprepared("insert z_reactiontotalsupvote
            select value, count(*) as totals
            from :_uid_upvote
            group by value;");
        $this->dbInput()->unprepared("insert z_reactiontotalsdownvote
            select value, count(*) as totals
            from :_uid_downvote
            group by value;");
        $this->dbInput()->unprepared("insert z_reactiontotals
            select * from (
                select u.value, u.total as up, d.total as down
                from z_reactiontotalsupvote u
                left join z_reactiontotalsdownvote d on u.value = d.value
                union
                select d.value, u.total as up, d.total as down
                from z_reactiontotalsdownvote d
                left join z_reactiontotalsupvote u on u.value = d.value
            ) as reactions");

        $map = [
            'tid' => 'DiscussionID',
            'cid' => 'CategoryID',
            'title' => 'Name',
            'content' => 'Body',
            'uid' => 'InsertUserID',
            'locked' => 'Closed',
            'pinned' => 'Announce',
            'timestamp' => 'DateInserted',
            'edited' => 'DateUpdated',
            'editor' => 'UpdateUserID',
            'viewcount' => 'CountViews',
            'Format=Markdown',
            'votes' => 'Score',
            'attributes' => 'Attributes',
            'poll' => 'Type',
            'FilterStringValue=poll',
        ];
        $filters = [
            'timestamp' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
            'edited' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
            'attributes' => \Porter\Filter\ExtractColonDelimReactions::class,
            'poll' => \Porter\Filter\NotEmptyToStringValue::class, // see above: 'FilterStringValue=poll',
        ];
        $this->export(
            'Discussion',
            "select p.tid, cid, title, content, p.uid, locked, pinned, p.timestamp,
                    p.edited, p.editor, viewcount, votes, 
                    poll._id as poll,
                    concat(ifnull(u.total, 0), ':', ifnull(d.total, 0)) as attributes
                from :_topic t
                left join :_post p on t.mainPid = p.pid
                left join z_reactiontotalsupvote u on u.value = t.mainPid
                left join z_reactiontotalsdownvote d on d.value = t.mainPid
                left join :_poll poll on p.tid = poll.tid
                where t.deleted != 1",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $this->dbInput()->unprepared("drop table if exists z_comments;");
        $this->dbInput()->unprepared("create table z_comments (
                pid int, content text, uid varchar(255), tid varchar(255),
                timestamp double, edited varchar(255), editor varchar(255),
                votes int, upvote int, downvote int, primary key(pid) );");
        $this->dbInput()->unprepared("insert ignore z_comments 
                    (pid, content, uid, tid, timestamp, edited, editor, votes)
            select p.pid, p.content, p.uid, p.tid, p.timestamp, p.edited, p.editor, p.votes
            from :_post p
            left join z_discussionids t on t.tid = p.pid
            where p.deleted != 1 and t.tid is null;");
        $this->dbInput()->unprepared("update z_comments as c
            join z_reactiontotals r on r.value = c.pid
            set c.upvote = r.upvote, c.downvote = r.downvote;");

        // Comments
        $map = [
            'content' => 'Body',
            'uid' => 'InsertUserID',
            'tid' => 'DiscussionID',
            'timestamp' => 'DateInserted',
            'edited' => 'DateUpdated',
            'editor' => 'UpdateUserID',
            'votes' => 'Score',
            'Format=Markdown',
            'attributes' => 'Attributes',
        ];
        $filters = [
            'timestamp' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
            'edited' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
            'attributes' => \Porter\Filter\ExtractColonDelimReactions::class,
        ];
        $this->export(
            'Comment',
            "select content, uid, tid, timestamp, edited, editor, votes,
                    concat(ifnull(upvote, 0), ':', ifnull(downvote, 0)) as attributes
                from z_comments",
            $map,
            $filters
        );
    }

    protected function polls(): void
    {
        $map = [
            'pollid' => 'PollID',
            'title' => 'Name',
            'tid' => 'DiscussionID',
            'votecount' => 'CountVotes',
            'uid' => 'InsertUserID',
            'timestamp' => 'DateInserted',
        ];
        $filters = [
            'timestamp' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
        ];
        $this->export(
            'Poll',
            "select *
                from :_poll p 
                left join :_poll_settings ps on ps._key like concat(p._key, ':', '%')",
            $map,
            $filters
        );

        $pollOption_Map = [
            '_num' => 'PollOptionID',
            '_key' => 'PollID',
            'title' => 'Body',
            'sort' => 'Sort',
            'votecount' => 'CountVotes',
            'Format=Html',
        ];
        $filters = [
            'votecount' => \Porter\Filter\EmptyToZero::class,
            '_key' => \Porter\Filter\ExtractColonDelimNumber::class,
        ];
        $this->export(
            'PollOption',
            "select _num, _key, title, id+1 as sort, votecount
                from :_poll_options
                where title is not null",
            $pollOption_Map
        );

        $pollVote_Map = [
            'userid' => 'UserID',
            'poll_option_id' => 'PollOptionID'
        ];
        $this->export(
            'PollVote',
            "select povm.members as userid, po._num as poll_option_id
                from :_poll_options_votes__members povm
                left join :_poll_options_votes pov on povm._parentid = pov._id
                left join :_poll_options po on pov._key like concat(po._key, ':', '%')
                where po.title is not null",
            $pollVote_Map
        );
    }

    protected function tags(): void
    {
        if (!$this->indexExists('z_idx_topic_key', ':_topic')) {
            $this->dbInput()->unprepared("create index z_idx_topic_key on :_topic (_key);");
        }
        $map = [
            'slug' => 'Name',
            'fullname' => 'FullName',
            'count' => 'CountDiscussions',
            'tagid' => 'TagID',
            'cid' => 'CategoryID',
            'type' => 'Type',
            'timestamp' => 'DateInserted',
            'uid' => 'InsertUserID'
        ];
        $filters = [
            'timestamp' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
            'slug' => \Porter\Filter\FormatUrl::class,
        ];
        $this->dbInput()->unprepared("set @rownr=1000;");
        $this->export(
            'Tag',
            "select @rownr:=@rownr+1 as tagid, members as fullname, members as slug, count, timestamp, uid, cid
                from (
                    select members, count(*) as count, _parentid
                    from :_topic_tags__members
                    group by members
                ) as tags
                join :_topic_tags tt on tt._id = _parentid
                left join :_topic t on substring(tt._key, 1, length(tt._key) - 5) = t._key",
            $map,
            $filters
        );

        $map = [
            'tagid' => 'TagID',
            'tid' => 'DiscussionID',
            'cid' => 'CategoryID',
            'timestamp' => 'DateInserted',
        ];
        $this->dbInput()->unprepared("set @rownr=1000;");
        $this->export(
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
                ) as tagids on two.members = tagids.fullname
                join :_topic_tags tt on tt._id = _parentid
                left join :_topic t on substring(tt._key, 1, length(tt._key) - 5) = t._key",
            $map,
            $filters
        );
    }

    protected function conversations(): void
    {
        if (!$this->indexExists('z_idx_message_key', ':_message')) {
            $this->dbInput()->unprepared("create index z_idx_message_key on :_message(_key);");
        }
        $this->dbInput()->unprepared("drop table if exists z_pmto;");
        $this->dbInput()->unprepared("create table z_pmto (
                pmid int unsigned,
                userid int,
                groupid int,
                primary key(pmid, userid) );");
        $this->dbInput()->unprepared("insert ignore z_pmto (pmid, userid)
            select substring_index(_key, ':', -1), fromuid
            from :_message;");
        $this->dbInput()->unprepared("insert ignore z_pmto (pmid, userid)
            select substring_index(_key, ':', -1), touid
            from :_message;");

        $this->dbInput()->unprepared("drop table if exists z_pmto2;");
        $this->dbInput()->unprepared("create table z_pmto2 (
                pmid int unsigned,
                userids varchar(250),
                groupid int unsigned,
                primary key (pmid) );");
        $this->dbInput()->unprepared("replace z_pmto2 (pmid, userids)
            select pmid, group_concat(userid order by userid)
            from z_pmto
            group by pmid;");

        $this->dbInput()->unprepared("drop table if exists z_pmgroup;");
        $this->dbInput()->unprepared("create table z_pmgroup (
                userids varchar(250),
                groupid varchar(255),
                firstmessageid int,
                lastmessageid int,
                countmessages int,
                primary key (userids, groupid) );");
        $this->dbInput()->unprepared("insert z_pmgroup
            select userids, concat('message:', min(pmid)), min(pmid), max(pmid), count(*)
            from z_pmto2
            group by userids;");
        $this->dbInput()->unprepared("update z_pmto2 as p
            left join z_pmgroup g
            on p.userids = g.userids
            set p.groupid = g.firstmessageid;");
        $this->dbInput()->unprepared("update z_pmto as p
            left join z_pmto2 p2 on p.pmid = p2.pmid
            set p.groupid = p2.groupid;");

        $this->dbInput()->unprepared("create index z_idx_pmto_cid on z_pmto(groupid);");
        $this->dbInput()->unprepared("create index z_idx_pmgroup_cid on z_pmgroup(firstmessageid);");

        $conversation_Map = [
            'conversationid' => 'ConversationID',
            'firstmessageid' => 'FirstMessageID',
            'lastmessageid' => 'LastMessageID',
            'countparticipants' => 'CountParticipants',
            'countmessages' => 'CountMessages'
        ];
        $this->export(
            'Conversation',
            "select *, firstmessageid as conversationid, 2 as countparticipants
            from z_pmgroup
            left join :_message on groupid = _key;",
            $conversation_Map
        );

        $map = [
            'messageid' => 'MessageID',
            'conversationid' => 'ConversationID',
            'content' => 'Body',
            'format' => 'Format',
            'fromuid' => 'InsertUserID',
            'timestamp' => 'DateInserted',
        ];
        $filters = [
            'timestamp' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
        ];
        $this->export(
            'ConversationMessage',
            "select groupid as conversationid, pmid as messageid, content, 'Text' as format, fromuid, timestamp
                from z_pmto2
                left join :_message on concat('message:', pmid) = _key",
            $map,
            $filters
        );

        $map = [
            'conversationid' => 'ConversationID',
            'userid' => 'UserID',
            'lastmessageid' => 'LastMessageID'
        ];
        $this->export(
            'UserConversation',
            "select p.groupid as conversationid, userid, lastmessageid
                from z_pmto p
                left join z_pmgroup on firstmessageid = p.groupid;",
            $map
        );
    }

    protected function bookmarks(): void
    {
        $map = [
            'members' => 'UserID',
            '_key' => 'DiscussionID',
            'bookmarked' => 'Bookmarked'
        ];
        $filters = [
            '_key' => \Porter\Filter\ExtractColonDelimNumber::class,
        ];
        $this->export(
            'UserDiscussion',
            "select members, _key, 1 as bookmarked
                from :_tid_followers__members
                left join :_tid_followers on _parentid = _id",
            $map,
            $filters
        );
    }

    protected function reactions(): void
    {
        if (!$this->indexExists('z_idx_topic_mainpid', ':_topic')) {
            $this->dbInput()->unprepared("create index z_idx_topic_mainpid on :_topic(mainPid);");
        }
        if (!$this->indexExists('z_idx_uid_downvote', ':_uid_downvote')) {
            $this->dbInput()->unprepared("create index z_idx_uid_downvote on :_uid_downvote(value);");
        }
        if (!$this->indexExists('z_idx_uid_upvote', ':_uid_upvote')) {
            $this->dbInput()->unprepared("create index z_idx_uid_upvote on :_uid_upvote(value);");
        }

        $map = [
            'tagid' => 'TagID',
            'recordtype' => 'RecordType',
            '_key' => 'UserID',
            'value' => 'RecordID',
            'score' => 'DateInserted',
            'total' => 'Total',
        ];
        $filters = [
            '_key' => \Porter\Filter\ExtractColonDelimNumber::class,
            'timestamp' => \Porter\Filter\UnixtimeMillisecondsToDate::class,
        ];
        $this->export(
            'UserTag',
            "select 11 as tagid, 'Discussion' as recordtype, u._key, u.value, score, total
                from :_uid_upvote u
                left join z_discussionids t on u.value = t.tid
                left join z_reactiontotalsupvote r on  r.value = u.value
                where u._key != 'uid:NaN:upvote' and t.tid is not null
                union
                select 11 as tagid, 'Comment' as recordtype, u._key, u.value, score, total
                from :_uid_upvote u
                left join z_discussionids t on u.value = t.tid
                left join z_reactiontotalsupvote r on  r.value = u.value
                where u._key != 'uid:NaN:upvote' and t.tid is null
                union
                select 10 as tagid, 'Discussion' as recordtype, u._key, u.value, score, total
                from :_uid_downvote u
                left join z_discussionids t on u.value = t.tid
                left join z_reactiontotalsdownvote r on  r.value = u.value
                where u._key != 'uid:NaN:downvote' and t.tid is not null
                union
                select 10 as tagid, 'Comment' as recordtype, u._key, u.value, score, total
                from :_uid_downvote u
                left join z_discussionids t on u.value = t.tid
                left join z_reactiontotalsdownvote r on  r.value = u.value
                where u._key != 'uid:NaN:downvote' and t.tid is null",
            $map,
            $filters
        );
    }
}
