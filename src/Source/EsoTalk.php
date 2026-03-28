<?php

/**
 * esotalk exporter tool.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 * @author  Frederik Nielsen
 */

namespace Porter\Source;

use Porter\Source;

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
     */
    public function run(): void
    {
        $this->users();
        $this->roles();
        $this->categories();
        $this->discussions();
        $this->comments();
        $this->bookmarks();
        $this->conversations();
    }

    /**
     */
    protected function users(): void
    {
        $user_Map = array(
            'memberId' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            'confirmed' => 'Verified',
            'password' => 'Password',
        );
        $this->export(
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
     */
    protected function roles(): void
    {
        $role_Map = array(
            'groupId' => 'RoleID',
            'name' => 'Name',
        );
        $this->export(
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
        $this->export(
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
     */
    protected function categories(): void
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
        $this->export(
            'Category',
            "select * from :_channel c",
            $category_Map
        );
    }

    /**
     */
    protected function discussions(): void
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
        $this->export(
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
     */
    protected function comments(): void
    {
        $comment_Map = array(
            'postId' => 'CommentID',
            'conversationId' => 'DiscussionID',
            'content' => 'Body',
            'memberId' => 'InsertUserID',
            'editMemberId' => 'UpdateUserID',
        );
        // Now we need to omit the comments we used as the OP.
        $this->export(
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
     */
    protected function bookmarks(): void
    {
        $userDiscussion_Map = array(
            'id' => 'UserID',
            'conversationId' => 'DiscussionID',
        );
        $this->export(
            'UserDiscussion',
            "select *
                from :_member_conversation
                where starred = 1",
            $userDiscussion_Map
        );
    }

    /**
     */
    protected function conversations(): void
    {
        $conversation_map = array(
            'conversationId' => 'ConversationID',
            'countPosts' => 'CountMessages',
            'startMemberId' => 'InsertUserID',
        );
        $this->export(
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
        $this->export(
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
        $this->export(
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
