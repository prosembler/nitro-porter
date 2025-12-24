<?php

/**
 * esotalk exporter tool.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 * @author  Frederik Nielsen
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class EsoTalk extends Source
{
    public const SUPPORTED = [
        'name' => 'esoTalk',
        'defaultTablePrefix' => 'et_',
        'charsetTable' => 'post',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Roles' => 1,
            'Avatars' => 0,
            'PrivateMessages' => 1,
            'Attachments' => 0,
            'Bookmarks' => 1,
        ]
    ];

    /**
     * Main export process.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->bookmarks($port);
        $this->conversations($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
            'memberId' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            'confirmed' => 'Verified',
            'password' => 'Password',
        );
        $port->export(
            'User',
            "select u.*, 'crypt' as HashMethod,
                    FROM_UNIXTIME(joinTime) as DateInserted,
                    FROM_UNIXTIME(lastActionTime) as DateLastActive,
                    if(account='suspended',1,0) as Banned
                from :_member u",
            $user_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'groupId' => 'RoleID',
            'name' => 'Name',
        );
        $port->export(
            'Role',
            "select groupId, name
                from :_group
                union select max(groupId)+1, 'Member' from :_group
                union select max(groupId)+2, 'Administrator' from :_group",
            $role_Map
        );

        // User Role.
        $userRole_Map = array(
            'memberId' => 'UserID',
            'groupId' => 'RoleID',
        );
        // Create fake 'member' and 'administrator' roles to account for them being set separately on member table.
        $port->export(
            'UserRole',
            "select u.memberId, u.groupId
                from :_member_group u
                union all
                select memberId, (select max(groupId)+1 from :_group) from :_member where account='member'
                union all
                select memberId, (select max(groupId)+2 from :_group) from :_member where account='administrator'",
            $userRole_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'channelId' => 'CategoryID',
            'title' => 'Name',
            'slug' => 'UrlCode',
            'description' => 'Description',
            'parentId' => 'ParentCategoryID',
            'countConversations' => 'CountDiscussions',
            //'countPosts' => 'CountComments',
        );
        $port->export(
            'Category',
            "select * from :_channel c",
            $category_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'conversationId' => 'DiscussionID',
            'title' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'channelId' => 'CategoryID',
            'memberId' => 'InsertUserID',
            'sticky' => 'Announce',
            'locked' => 'Closed',
            //'countPosts' => 'CountComments',
            'lastPostMemberId' => 'LastCommentUserID',
            'content' => 'Body',
        );
        // The body of the OP is in the post table.
        $port->export(
            'Discussion',
            "select
                    c.conversationId,
                    c.title,
                    c.channelId,
                    p.memberId,
                    c.sticky,
                    c.locked,
                    c.lastPostMemberId,
                    p.content,
                    'BBCode' as Format,
                    from_unixtime(startTime) as DateInserted,
                    from_unixtime(lastPostTime) as DateLastComment
                from :_conversation c
                left join :_post p
                    on p.conversationId = c.conversationId
                where private = 0
                group by c.conversationId
                order by p.time",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'postId' => 'CommentID',
            'conversationId' => 'DiscussionID',
            'content' => 'Body',
            'memberId' => 'InsertUserID',
            'editMemberId' => 'UpdateUserID',
        );
        // Now we need to omit the comments we used as the OP.
        $port->export(
            'Comment',
            "select p.*,
                    'BBCode' as Format,
                    from_unixtime(time) as DateInserted,
                    from_unixtime(editTime) as DateUpdated
                from :_post p
                inner join :_conversation c ON c.conversationId = p.conversationId
                and c.private = 0
                join
                    ( select conversationId,
                        min(postId) as m
                    from :_post
                    group by conversationId) r on r.conversationId = c.conversationId
                where p.postId<>r.m",
            $comment_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function bookmarks(Migration $port): void
    {
        $userDiscussion_Map = array(
            'id' => 'UserID',
            'conversationId' => 'DiscussionID',
        );
        $port->export(
            'UserDiscussion',
            "select *
                from :_member_conversation
                where starred = 1",
            $userDiscussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        $conversation_map = array(
            'conversationId' => 'ConversationID',
            'countPosts' => 'CountMessages',
            'startMemberId' => 'InsertUserID',
        );
        $port->export(
            'Conversation',
            "select p.*,
                    'BBCode' as Format,
                    from_unixtime(time) as DateInserted,
                    from_unixtime(lastposttime) as DateUpdated
                from :_post p
                inner join :_conversation c on c.conversationId = p.conversationId
                and c.private = 1",
            $conversation_map
        );

        $userConversation_map = array(
            'conversationId' => 'ConversationID',
            'memberId' => 'UserID',

        );
        $port->export(
            'UserConversation',
            "select distinct a.fromMemberId as memberId, a.type, c.private, c.conversationId from :_activity a
                inner join :_conversation c on c.conversationId = a.conversationId
                and c.private = 1 and a.type = 'privateAdd'
                union all
                select distinct a.memberId as memberId, a.type, c.private, c.conversationId from :_activity a
                inner join :_conversation c on c.conversationId = a.conversationId
                and c.private = 1 and a.type = 'privateAdd'",
            $userConversation_map
        );

        $userConversationMessage_map = array(
            'postId' => 'MessageID',
            'conversationId' => 'ConversationID',
            'content' => 'Body',
            'memberId' => 'InsertUserID',

        );
        $port->export(
            'ConversationMessage',
            "select p.*,
                    'BBCode' as Format,
                    from_unixtime(time) as DateInserted
                from :_post p
                inner join :_conversation c on c.conversationId = p.conversationId and c.private = 1",
            $userConversationMessage_map
        );
    }
}
