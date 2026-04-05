<?php

/**
 * Invision Powerboard 4.x exporter tool.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

/**
 * Formatting issues?
 * @see https://github.com/prosembler/vanilla/tree/main/plugins/IPBFormatter
 */
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
     */
    public function run(): void
    {
        $this->users();
        $this->roles();

        $this->categories();
        $this->discussions();
        $this->comments();
        $this->attachments();

        $this->conversations();
    }

    /**
     */
    protected function conversations(): void
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
        $this->export('Conversation', $query, $map, $filters);

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
        $query = "select m.*, 'Html' as Format from :_core_message_posts m";
        $this->export('ConversationMessage', $query, $map, $filters);

        // User Conversation.
        $map = [
            'map_user_id' => 'UserID',
            'map_topic_id' => 'ConversationID',
        ];
        $query = "select t.*, !map_user_active as Deleted
            from :_core_message_topic_user_map t";
        $this->export('UserConversation', $query, $map);
    }

    /**
     */
    protected function users(): void
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
        $this->export('User', $query, $map, $filters);
    }

    /**
     */
    protected function roles(): void
    {
        $map = [
            'g_id' => 'RoleID',
            'g_title' => 'Name'
        ];
        $this->export('Role', "select * from :_core_groups", $map);

        // User Role.
        $map = [
            'member_id' => 'UserID',
            'member_group_id' => 'RoleID'
        ];
        $query = "select m.member_id, m.member_group_id from :_core_members m";
        $this->export('UserRole', $query, $map);
    }

    /**
     */
    protected function categories(): void
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
        $this->export('Category', "select * from :_forums_forums", $map, $filters);
    }

    /**
     */
    protected function discussions(): void
    {
        $descriptionSQL = 'p.post';
        $hasTopicDescription = ($this->hasInputSchema('forums_topics', array('description')) === true);
        if ($hasTopicDescription || $this->hasInputSchema('forums_posts', array('description')) === true) {
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
        $query = "select t.*, $descriptionSQL as post, 
                IF(t.state = 'closed', 1, 0) as closed, 'Html' as Format, p.edit_time
            from :_forums_topics t
            left join :_forums_posts p on t.topic_firstpost = p.pid";
        $this->export('Discussion', $query, $map, $filters);
    }

    /**
     */
    protected function comments(): void
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
        $query = "select p.*, 'Html' as Format
            from :_forums_posts p
            join :_forums_topics t on p.topic_id = t.tid
            where p.pid <> t.topic_firstpost";
        $this->export('Comment', $query, $map, $filters);
    }

    /**
     */
    protected function attachments(): void
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
        $this->export('Media', $query, $map, $filters);
    }
}
