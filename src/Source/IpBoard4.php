<?php

/**
 * Invision Powerboard 4.x exporter tool.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class IpBoard4 extends Source
{
    public const SUPPORTED = [
        'name' => 'IP.Board 4',
        'defaultTablePrefix' => 'ibf_',
        'charsetTable' => 'forums_posts',
        'passwordHashMethod' => 'ipb',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Roles' => 1,
            'PrivateMessages' => 1,
            'Attachments' => 1,
            'Bookmarks' => 0,
            'Tags' => 0,
        ]
    ];

    /**
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);

        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->attachments($port);

        $this->conversations($port);
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        // Conversations.
        $map = [
            'mt_id' => 'ConversationID',
            'mt_date' => 'DateInserted',
            'mt_title' => 'Subject',
            'mt_starter_id' => 'InsertUserID'
        ];
        $filters = [
            'mt_date' => 'timestampToDate',
        ];
        $query = "select * from :_core_message_topics where mt_is_deleted = 0";
        $port->export('Conversation', $query, $map, $filters);

        // Conversation Message.
        $map = [
            'msg_id' => 'MessageID',
            'msg_topic_id' => 'ConversationID',
            'msg_date' => 'DateInserted',
            'msg_post' => 'Body',
            'msg_author_id' => 'InsertUserID',
            'msg_ip_address' => 'InsertIPAddress'
        ];
        $filters = [
            'msg_date' => 'timestampToDate',
        ];
        $query = "select m.*, 'IPB' as Format from :_core_message_posts m";
        $port->export('ConversationMessage', $query, $map, $filters);

        // User Conversation.
        $map = [
            'map_user_id' => 'UserID',
            'map_topic_id' => 'ConversationID',
        ];
        $query = "select t.*, !map_user_active as Deleted
            from :_core_message_topic_user_map t";
        $port->export('UserConversation', $query, $map);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $map = [
            'member_id' => 'UserID',
            'name' => 'Name',
            'email' => 'Email',
            'joined' => 'DateInserted',
            'ip_address' => 'InsertIPAddress',
            'time_offset' => 'HourOffset',
            'last_activity' => 'DateLastActive',
            'member_banned' => 'Banned',
            'title' => 'Title',
            'location' => 'Location'
        ];
        $filters = [
            'name' => 'HtmlDecoder',
            'joined' => 'timestampToDate',
            'last_activity' => 'timestampToDate',
        ];
        $query = "select m.*, 'ipb' as HashMethod
            from :_core_members m";
        $port->export('User', $query, $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $map = [
            'g_id' => 'RoleID',
            'g_title' => 'Name'
        ];
        $port->export('Role', "select * from :_core_groups", $map);

        // User Role.
        $map = [
            'member_id' => 'UserID',
            'member_group_id' => 'RoleID'
        ];
        $query = "select m.member_id, m.member_group_id from :_core_members m";
        $port->export('UserRole', $query, $map);
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $map = [
            'id' => 'CategoryID',
            'name' => 'Name',
            'name_seo' => 'UrlCode',
            'description' => 'Description',
            'parent_id' => 'ParentCategoryID',
            'position' => 'Sort'
        ];
        $filters = [
            'name' => 'HtmlDecoder',
        ];
        $port->export('Category', "select * from :_forums_forums", $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $descriptionSQL = 'p.post';
        $hasTopicDescription = ($port->hasInputSchema('forums_topics', array('description')) === true);
        if ($hasTopicDescription || $port->hasInputSchema('forums_posts', array('description')) === true) {
            $description = ($hasTopicDescription) ? 't.description' : 'p.description';
            $descriptionSQL = "case
                when $description <> '' and p.post is not null
                    then concat('<div class=\"IPBDescription\">', $description, '</div>', p.post)
                when $description <> '' then $description
                else p.post
            end";
        }
        $map = [
            'tid' => 'DiscussionID',
            'title' => 'Name',
            'description' => 'SubName',
            'forum_id' => 'CategoryID',
            'starter_id' => 'InsertUserID',
            'start_date' => 'DateInserted',
            'edit_time' => 'DateUpdated',
            'posts' => 'CountComments',
            'views' => 'CountViews',
            'pinned' => 'Announce',
            'post' => 'Body',
            'closed' => 'Closed'
        ];
        $filters = [
            'start_date' => 'timestampToDate',
            'edit_time' => 'timestampToDate',
        ];
        $query = "select t.*,
                $descriptionSQL as post,
                IF(t.state = 'closed', 1, 0) as closed,
                'BBCode' as Format,
                p.edit_time
            from :_forums_topics t
            left join :_forums_posts p
                on t.topic_firstpost = p.pid";
        $port->export('Discussion', $query, $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $map = [
            'pid' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'author_id' => 'InsertUserID',
            'ip_address' => 'InsertIPAddress',
            'post_date' => 'DateInserted',
            'edit_time' => 'DateUpdated',
            'post' => 'Body'
        ];
        $filters = [
            'post_date' => 'timestampToDate',
            'edit_time' => 'timestampToDate',
        ];
        $query = "select p.*,
                'BBCode' as Format
            from :_forums_posts p
            join :_forums_topics t
                on p.topic_id = t.tid
            where p.pid <> t.topic_firstpost";
        $port->export('Comment', $query, $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function attachments(Migration $port): void
    {
        $map = [
            'attach_id' => 'MediaID',
            'attach_file' => 'Name',
            'attach_path' => 'Path',
            'attach_date' => 'DateInserted',
            'thumb_path' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
            'attach_member_id' => 'InsertUserID',
            'attach_filesize' => 'Size',
            'img_width' => 'ImageWidth',
            'img_height' => 'ImageHeight'
        ];
        $filters = [
            'attach_date' => 'timestampToDate',
        ];
        $query = "select a.*
            from :_core_attachments a";
        $port->export('Media', $query, $map, $filters);
    }
}
