<?php

/**
 * jforum exporter tool.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;

class JForum extends Source
{
    public const array INFO = [
        'name' => 'jforum',
        'defaultTablePrefix' => 'jforum_',
        'charsetTable' => 'posts',
    ];

    public array $sourceTables = [
        'forums' => [],
        'posts' => [],
        'topics' => [],
        'users' => ['user_id', 'username', 'user_email'],
    ];

    protected function users(): void
    {
        $map = [
            'user_id' => 'UserID',
            'username' => 'Name',
            'user_email' => 'Email',
            'user_regdate' => 'DateInserted',
            'user_posts' => 'CountComments',
            'deleted' => 'Deleted',
            'user_from' => 'Location',
            'user_biography' => 'About',
            'HashMethod=Reset',
        ];
        $this->export('User', "select *, u.user_regdate as DateFirstVisit from :_users as u", $map);
    }

    protected function roles(): void
    {
        $this->export(
            'Role',
            "select g.group_id as RoleID, g.group_name as Name, g.group_description as Description
            from :_groups as g"
        );

        // User Role.
        $this->export(
            'UserRole',
            "select u.user_id as UserID, u.group_id as RoleID from :_user_groups as u"
        );
    }

    protected function signatures(): void
    {
        $this->export( // @todo split non-signature data to users()
            'UserMeta',
            "select user_id as UserID, 'Profile.Website' as `Name`, user_website as `Value`
                from :_users
                where user_website is not null
                union
                select user_id, 'Plugins.Signatures.Sig', user_sig
                from :_users
                where user_sig is not null
                union
                select user_id, 'Plugins.Signatures.Format', 'BBCode'
                from :_users
                where user_sig is not null
                union
                select user_id, 'Profile.Occupation', user_occ
                from :_users
                where user_occ is not null
                union
                select user_id, 'Profile.Interests', user_interests
                from :_users
                where user_interests is not null"
        );
    }

    protected function categories(): void
    {
        // _categories is tier 1, _forum is tier 2.
        // Overlapping IDs, so fast-forward _categories by 1000.
        $this->export(
            'Category',
            "select c.categories_id+1000 as CategoryID,
                    -1 as ParentCategoryID,
                    c.title as Name,
                    null as Description,
                    1 as Depth,
                    c.display_order as Sort
                from :_categories as c
                union
                select f.forum_id as CategoryID,
                    f.categories_id+1000 as ParentCategoryID,
                    f.forum_name as Name,
                    f.forum_desc as Description,
                    2 as Depth,
                    null as Sort
                from :_forums as f"
        );
    }

    protected function discussions(): void
    {
        $postTextColumm = 'p.post_text';
        $postTextSource = '';
        if ($this->hasInputSchema(':_posts_text')) {
            $postTextColumm = 't.post_text';
            $postTextSource = 'left join :_posts_text t on p.post_id = t.post_id';
        }
        $map = [
            'topic_id' => 'DiscussionID',
            'forum_id' => 'CategoryID',
            'user_id' => 'InsertUserID',
            'topic_title' => 'Name',
            'post_text' => 'Body',
            'topic_time' => 'DateInserted',
            'topic_views' => 'CountViews',
            'topic_replies' => 'CountComments',
            'topic_status' => 'Closed',
            'Format=BBCode',
        ];
        $filters = ['topic_title' => \Porter\Filter\DecodeHtml::class];
        $this->export(
            'Discussion',
            "select t.*, if (t.topic_type > 0, 1, 0) as Announce, $postTextColumm
                from :_topics as t
                left join :_posts p on t.topic_first_post_id = p.post_id
                $postTextSource",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $postTextColumm = 'p.post_text';
        $postTextSource = '';
        if ($this->hasInputSchema(':_posts_text')) {
            $postTextColumm = 't.post_text';
            $postTextSource = 'left join :_posts_text t on p.post_id = t.post_id';
        }
        $map = [
            'post_id' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'user_id' => 'InsertUserID',
            'post_text' => 'Body',
            'post_time' => 'DateInserted',
            'post_edit_time' => 'DateUpdated',
            'Format=BBCode',
        ];
        $this->export(
            'Comment',
            "select p.post_id, p.topic_id, p.user_id, p.post_time, p.post_edit_time, $postTextColumm
                from :_posts as p
                $postTextSource
                left join jforum_topics as t on t.topic_first_post_id = p.post_id
                where t.topic_first_post_id is null",
            $map
        );
    }

    protected function bookmarks(): void
    {
        $map = [
            'topic_id' => 'DiscussionID',
            'user_id' => 'UserID',
            'Bookmarked=1',
        ];
        $this->export(
            'UserDiscussion',
            "select w.topic_id, w.user_id, if (w.is_read, now(), null) as DateLastViewed
                from :_topics_watch as w",
            $map
        );
    }

    protected function conversations(): void
    {
        // Thread using tmp table based on the pair of users talking.
        if (!$this->indexExists('ix_zconversation_from_to', ':_privmsgs')) {
            $this->dbInput()->unprepared('create index ix_zconversation_from_to
                on :_privmsgs (privmsgs_from_userid, privmsgs_to_userid)');
        }
        $this->dbInput()->unprepared("drop table if exists z_conversation;");
        $this->dbInput()->unprepared("create table z_conversation (
            ConversationID int unsigned not null auto_increment,
            LowUserID int unsigned,
            HighUserID int unsigned,
            primary key (ConversationID), index idx_lowuser_highuser (LowUserID, HighUserID))");
        $this->dbInput()->unprepared("insert into z_conversation (LowUserID, HighUserID)
            select least(privmsgs_from_userid, privmsgs_to_userid),
                greatest(privmsgs_from_userid, privmsgs_to_userid)
            from :_privmsgs
            group by least(privmsgs_from_userid, privmsgs_to_userid),
                greatest(privmsgs_from_userid, privmsgs_to_userid)");

        // Replying on /dba/counts to rebuild most of this data later.
        $map = [
            'privmsgs_from_userid' => 'InsertUserID',
            'privmsgs_date' => 'DateInserted',
            'privmsgs_subject' => 'Subject',
        ];
        $this->export(
            'Conversation',
            "select p.privmsgs_from_userid, p.privmsgs_date,  p.privmsgs_subject, c.ConversationID
                from :_privmsgs as p
                left join z_conversation as c on c.HighUserID = greatest(p.privmsgs_from_userid, p.privmsgs_to_userid)
                    and c.LowUserID = least(p.privmsgs_from_userid, p.privmsgs_to_userid)
                group by least(privmsgs_from_userid, privmsgs_to_userid),
                    greatest(privmsgs_from_userid, privmsgs_to_userid)",
            $map
        );

        // Conversation Message.
        // Messages with the same timestamps are sent/received copies.
        // Yes that'd probably break down on huge sites but it's too convenient to pass up for now.
        $map = [
            'privmsgs_id' => 'MessageID',
            'privmsgs_from_userid' => 'InsertUserID',
            'privmsgs_date' => 'DateInserted',
            'privmsgs_text' => 'Body',
            'Format=BBCode',
        ];
        $this->export(
            'ConversationMessage',
            "select p.privmsgs_id, p.privmsgs_from_userid, p.privmsgs_date, t.privmsgs_text, c.ConversationID
                from :_privmsgs p
                left join :_privmsgs_text t on t.privmsgs_id = p.privmsgs_id
                left join z_conversation c on c.LowUserID = least(privmsgs_from_userid, privmsgs_to_userid)
                    and c.HighUserID = greatest(privmsgs_from_userid, privmsgs_to_userid)
                group by privmsgs_date",
            $map
        );

        // UserConversation
        $this->export(
            'UserConversation',
            "select  ConversationID, LowUserID as UserID, now() as DateLastViewed
                from z_conversation
                union
                select  ConversationID, HighUserID as UserID, now() as DateLastViewed
                from z_conversation"
        );
        Log::comment('Run the following query after the import: ');
        Log::comment('update GDN_UserConversation
            set CountReadMessages = (select count(MessageID)
            from GDN_ConversationMessage
            where GDN_ConversationMessage.ConversationID = GDN_UserConversation.ConversationID)');
    }
}
