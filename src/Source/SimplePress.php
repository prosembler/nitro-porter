<?php

/**
 * Simple:Press exporter tool
 *
 * @author  Todd Burry
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class SimplePress extends Source
{
    public const SUPPORTED = [
        'name' => 'SimplePress 1',
        'defaultTablePrefix' => 'wp_',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'Vanilla',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 0,
            'PrivateMessages' => 1,
            'Attachments' => 0,
            'Bookmarks' => 0,
        ]
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = array(
        'sfforums' => array(),
        'sfposts' => array(),
        'sftopics' => array(),
        'users' => array('ID', 'user_nicename', 'user_pass', 'user_email', 'user_registered'),
    );

    /**
     * Forum-specific export format.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->categories($port);
        $this->discussions($port);
        $this->tags($port);
        $this->comments($port);
        $this->conversations($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
            'user_id' => 'UserID',
            'display_name' => 'Name',
            'user_pass' => 'Password',
            'user_email' => 'Email',
            'user_registered' => 'DateInserted',
            'lastvisit' => 'DateLastActive'
        );
        $port->export(
            'User',
            "select m.*, u.user_pass, u.user_email, u.user_registered
                from :_users u
                join :_sfmembers m
                    on u.ID = m.user_id;",
            $user_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'usergroup_id' => 'RoleID',
            'usergroup_name' => 'Name',
            'usergroup_desc' => 'Description'
        );
        $port->export(
            'Role',
            "select
                usergroup_id,
                usergroup_name,
                usergroup_desc
            from :_sfusergroups
             union
             select
                100,
                'Administrators',
                ''",
            $role_Map
        );

        // UserRoles
        $userRole_Map = array(
            'user_id' => 'UserID',
            'usergroup_id' => 'RoleID'
        );
        $port->export(
            'UserRole',
            "select
                    m.user_id,
                    m.usergroup_id
                from :_sfmemberships m
                union
                select
                    um.user_id,
                    100
                from :_usermeta um
                where um.meta_key = 'wp_capabilities'
                    and um.meta_value like '%PF Manage Forums%'",
            $userRole_Map
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
            'forum_seq' => 'Sort',
            'form_slug' => 'UrlCode',
            'parent_id' => 'ParentCategoryID'
        );
        $port->export(
            'Category',
            "select
                    f.forum_id,
                    f.forum_name,
                    f.forum_seq,
                    f.forum_desc,
                    lower(f.forum_slug) as forum_slug,
                    case when f.parent = 0 then f.group_id + 1000 else f.parent end as parent_id
                from :_sfforums f
                union
                select
                    1000 + g.group_id,
                    g.group_name,
                    g.group_seq,
                    g.group_desc,
                    null,
                    null
                from :_sfgroups g",
            $category_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function tags(Migration $port): void
    {
        if ($port->hasInputSchema('sftags')) {
            // Tags
            $tag_Map = array(
                'tag_id' => 'TagID',
                'tag_name' => 'Name'
            );
            $port->export('Tag', "select * from :_sftags", $tag_Map);

            if ($port->hasInputSchema('sftagmeta')) {
                $tagDiscussion_Map = array(
                    'tag_id' => 'TagID',
                    'topic_id' => 'DiscussionID'
                );
                $port->export('TagDiscussion', "select * from :_sftagmeta", $tagDiscussion_Map);
            }
        }
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'topic_id' => 'DiscussionID',
            'forum_id' => 'CategoryID',
            'user_id' => 'InsertUserID',
            'topic_name' => 'Name',
            'Format' => 'Format',
            'topic_date' => 'DateInserted',
            'topic_pinned' => 'Announce',
            'topic_slug' => array('Column' => 'Slug', 'Type' => 'varchar(200)')
        );
        $port->export(
            'Discussion',
            "select t.*, 'Html' as Format from :_sftopics t",
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
            'post_content' => 'Body',
            'Format' => 'Format',
            'user_id' => 'InsertUserID',
            'post_date' => 'DateInserted',
            'poster_ip' => 'InsertIPAddress'
        );
        $port->export(
            'Comment',
            "select p.*, 'Html' as Format from :_sfposts p",
            $comment_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        $conv_Map = array(
            'message_id' => 'ConversationID',
            'from_id' => 'InsertUserID',
            'sent_date' => 'DateInserted'
        );
        $port->export(
            'Conversation',
            "select * from :_sfmessages where is_reply = 0",
            $conv_Map
        );

        // ConversationMessage.
        $convMessage_Map = array(
            'message_id' => 'MessageID',
            'from_id' => 'InsertUserID',
            'message' => array('Column' => 'Body')
        );
        $port->export(
            'ConversationMessage',
            'select c.message_id as ConversationID, m.*
                from :_sfmessages c
                join :_sfmessages m
                    on (m.is_reply = 0 and m.message_id = c.message_id)
                    or (m.is_reply = 1 and c.is_reply = 0 and m.message_slug = c.message_slug
                    and m.from_id in (c.from_id, c.to_id) and m.to_id in (c.from_id, c.to_id));',
            $convMessage_Map
        );

        // UserConversation
        $userConv_Map = array(
            'message_id' => 'ConversationID',
            'from_id' => 'UserID'
        );
        $port->export(
            'UserConversation',
            'select message_id, from_id
                from :_sfmessages
                where is_reply = 0
                union
                select message_id, to_id
                from :_sfmessages
                where is_reply = 0',
            $userConv_Map
        );
    }
}
