<?php

/**
 * jforum exporter tool.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;
use Porter\Migration;

class JForum extends Source
{
    public const SUPPORTED = [
        'name' => 'jforum',
        'defaultTablePrefix' => 'jforum_',
        'charsetTable' => 'posts',
        'features' => [
            'Users' => 1,
            'Passwords' => 0,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 1,
            'Signatures' => 1,
            'Attachments' => 0,
            'Bookmarks' => 1,
        ]
    ];

    /**
     * You can use this to require certain tables and columns be present.
     *
     * This can be useful for verifying data integrity. Don't specify more columns
     * than your porter actually requires to avoid forwards-compatibility issues.
     *
     * @var array Required tables => columns
     */
    public array $sourceTables = array(
        'forums' => array(), // This just requires the 'forum' table without caring about columns.
        'posts' => array(),
        'topics' => array(),
        'users' => array('user_id', 'username', 'user_email'), // Require specific cols on 'users'
    );

    /**
     * Main export process.
     *
     * @param Migration $port
     */
    public function run(?Migration $port = null): void
    {
        $this->users($port);
        $this->roles($port);
        $this->userMeta($port);

        $this->categories($port);
        if ($port->hasInputSchema(':_posts_text')) {
            $postTextColumm = 't.post_text as Body';
            $postTextSource = 'left join :_posts_text t on p.post_id = t.post_id';
        } else {
            $postTextColumm = 'p.post_text as Body';
            $postTextSource = '';
        }
        $this->discussions($port, $postTextColumm, $postTextSource);
        $this->comments($port, $postTextColumm, $postTextSource);
        $this->bookmarks($port);
        $this->conversations($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $port->export(
            'User',
            "select
                    u.user_id as UserID,
                    u.username as Name,
                    'Reset' as HashMethod,
                    u.user_email as Email,
                    u.user_regdate as DateInserted,
                    u.user_regdate as DateFirstVisit,
                    u.user_posts as CountComments,
                    u.user_avatar as Photo,
                    u.deleted as Deleted,
                    u.user_from as Location,
                    u.user_biography as About
                from :_users as u"
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $port->export(
            'Role',
            "
            select
                g.group_id as RoleID,
                g.group_name as Name,
                g.group_description as Description
            from :_groups as g"
        );

        // User Role.
        $port->export(
            'UserRole',
            "
            select
                u.user_id as UserID,
                u.group_id as RoleID
            from :_user_groups as u"
        );
    }

    /**
     * @param Migration $port
     */
    protected function userMeta(Migration $port): void
    {
        $port->export(
            'UserMeta',
            "select
                    user_id as UserID,
                    'Profile.Website' as `Name`,
                    user_website as `Value`
                from :_users
                where user_website is not null
                union
                select
                    user_id,
                    'Plugins.Signatures.Sig',
                    user_sig
                from :_users
                where user_sig is not null
                union
                select
                    user_id,
                    'Plugins.Signatures.Format',
                    'BBCode'
                from :_users
                where user_sig is not null
                union
                select
                    user_id,
                    'Profile.Occupation',
                    user_occ
                from :_users
                where user_occ is not null
                union
                select
                    user_id,
                    'Profile.Interests',
                    user_interests
                from :_users
                where user_interests is not null"
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
// _categories is tier 1, _forum is tier 2.
        // Overlapping IDs, so fast-forward _categories by 1000.
        $port->export(
            'Category',
            "select
                    c.categories_id+1000 as CategoryID,
                    -1 as ParentCategoryID,
                    c.title as Name,
                    null as Description,
                    1 as Depth,
                    c.display_order as Sort
                from :_categories as c
                union
                select
                    f.forum_id as CategoryID,
                    f.categories_id+1000 as ParentCategoryID,
                    f.forum_name as Name,
                    f.forum_desc as Description,
                    2 as Depth,
                    null as Sort
                from :_forums as f"
        );
    }

    /**
     * @param Migration $port
     * @param string $postTextColumm
     * @param string $postTextSource
     */
    protected function discussions(Migration $port, string $postTextColumm, string $postTextSource): void
    {
        $discussion_Map = array(
            'topic_title' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
        );
        // It's easier to convert between Unix time and MySQL datestamps during the db query.
        $port->export(
            'Discussion',
            "select
                    t.topic_id as DiscussionID,
                    t.forum_id as CategoryID,
                    t.user_id as InsertUserID,
                    t.topic_time as DateInserted,
                    t.topic_title,
                    t.topic_views as CountViews,
                    t.topic_replies as CountComments,
                    t.topic_status as Closed,
                    if (t.topic_type > 0, 1, 0) as Announce,
                    $postTextColumm,
                    'BBCode' as Format
                from :_topics as t
                    left join :_posts p on t.topic_first_post_id = p.post_id
                    $postTextSource",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     * @param string $postTextColumm
     * @param string $postTextSource
     */
    protected function comments(Migration $port, string $postTextColumm, string $postTextSource): void
    {
        $port->export(
            'Comment',
            "select
                    p.post_id as CommentID,
                    p.topic_id as DiscussionID,
                    p.user_id as InsertUserID,
                    p.poster_ip as InsertIPAddress,
                    p.post_time as DateInserted,
                    p.post_edit_time as DateUpdated,
                    'BBCode' as Format,
                    $postTextColumm
                from :_posts as p
                    $postTextSource
                    left join jforum_topics as t on t.topic_first_post_id = p.post_id
                where t.topic_first_post_id is null"
        );
    }

    /**
     * @param Migration $port
     */
    protected function bookmarks(Migration $port): void
    {
        // Guessing table is called "_watch" because they are all bookmarks.
        $userDiscussion_Map = array(
            'topic_id' => 'DiscussionID',
            'user_id' => 'UserID',
        );
        $port->export(
            'UserDiscussion',
            "select
                    w.topic_id as DiscussionID,
                    w.user_id as UserID,
                    1 as Bookmarked,
                    if (w.is_read, now(), null) as DateLastViewed
                from :_topics_watch as w",
            $userDiscussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        // Thread using tmp table based on the pair of users talking.
        if (!$port->indexExists('ix_zconversation_from_to', ':_privmsgs')) {
            $port->query('create index ix_zconversation_from_to
                on :_privmsgs (privmsgs_from_userid, privmsgs_to_userid)');
        }
        $port->query("drop table if exists z_conversation;");
        $port->query(
            "create table z_conversation (
                ConversationID int unsigned not null auto_increment,
                LowUserID int unsigned,
                HighUserID int unsigned,
                primary key (ConversationID),
                index idx_lowuser_highuser (LowUserID, HighUserID)
            ) engine=InnoDB default charset=utf8 collate=utf8_unicode_ci;"
        );
        $port->query(
            "insert into z_conversation (LowUserID, HighUserID)
                select
                    least(privmsgs_from_userid, privmsgs_to_userid),
                    greatest(privmsgs_from_userid, privmsgs_to_userid)
                from :_privmsgs
                group by
                    least(privmsgs_from_userid, privmsgs_to_userid),
                    greatest(privmsgs_from_userid, privmsgs_to_userid)"
        );

        // Replying on /dba/counts to rebuild most of this data later.
        $conversation_Map = array(
            'privmsgs_from_userid' => 'InsertUserID',
            'privmsgs_date' => 'DateInserted',
            'privmsgs_subject' => 'Subject',
        );
        $port->export(
            'Conversation',
            "select
                    p.privmsgs_from_userid as InsertUserID,
                    p.privmsgs_date as DateInserted,
                    p.privmsgs_subject as Subject,
                    c.ConversationID
                from :_privmsgs as p
                    left join z_conversation as c
                        on c.HighUserID = greatest(p.privmsgs_from_userid, p.privmsgs_to_userid)
                        and c.LowUserID = least(p.privmsgs_from_userid, p.privmsgs_to_userid)
                group by
                    least(privmsgs_from_userid, privmsgs_to_userid),
                    greatest(privmsgs_from_userid, privmsgs_to_userid)",
            $conversation_Map
        );

        // Conversation Message.
        // Messages with the same timestamps are sent/received copies.
        // Yes that'd probably break down on huge sites but it's too convenient to pass up for now.
        $message_Map = array(
            'privmsgs_id' => 'MessageID',
            'privmsgs_from_userid' => 'InsertUserID',
            'privmsgs_date' => 'DateInserted',
            'privmsgs_text' => 'Body',
        );
        $port->export(
            'ConversationMessage',
            "select
                    p.privmsgs_id as MessageID,
                    p.privmsgs_from_userid as InsertUserID,
                    p.privmsgs_date as DateInserted,
                    t.privmsgs_text as Body,
                    c.ConversationID,
                    'BBCode' as Format
                from :_privmsgs p
                    left join :_privmsgs_text t on t.privmsgs_id = p.privmsgs_id
                    left join z_conversation c on c.LowUserID = least(privmsgs_from_userid, privmsgs_to_userid)
                        and c.HighUserID = greatest(privmsgs_from_userid, privmsgs_to_userid)
                group by privmsgs_date",
            $message_Map
        );

        // UserConversation
        $port->export(
            'UserConversation',
            "select
                    ConversationID,
                    LowUserID as UserID,
                    now() as DateLastViewed
                from z_conversation
                union
                select
                    ConversationID,
                    HighUserID as UserID,
                    now() as DateLastViewed
                from z_conversation"
        );

        Log::comment('Run the following query after the import: ');
        Log::comment('update GDN_UserConversation
            set CountReadMessages = (select count(MessageID)
            from GDN_ConversationMessage
            where GDN_ConversationMessage.ConversationID = GDN_UserConversation.ConversationID)');
    }
}
