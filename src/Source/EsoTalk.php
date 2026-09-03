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
    public const array INFO = [
        'name' => 'esoTalk',
        'defaultTablePrefix' => 'et_',
        'charsetTable' => 'post',
    ];

    protected function users(): void
    {
        $user_Map = [
            'memberId' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            'confirmed' => 'Verified',
            'password' => 'Password',
        ];
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

    protected function roles(): void
    {
        $role_Map = [
            'groupId' => 'RoleID',
            'name' => 'Name',
        ];
        $this->export(
            'Role',
            "select groupId, name
                from :_group
                union select max(groupId)+1, 'Member' from :_group
                union select max(groupId)+2, 'Administrator' from :_group",
            $role_Map
        );

        // User Role.
        $userRole_Map = [
            'memberId' => 'UserID',
            'groupId' => 'RoleID',
        ];
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

    protected function categories(): void
    {
        $category_Map = [
            'channelId' => 'CategoryID',
            'title' => 'Name',
            'slug' => 'UrlCode',
            'description' => 'Description',
            'parentId' => 'ParentCategoryID',
            'countConversations' => 'CountDiscussions',
            //'countPosts' => 'CountComments',
        ];
        $this->export(
            'Category',
            "select * from :_channel c",
            $category_Map
        );
    }

    protected function discussions(): void
    {
        $discussion_Map = [
            'conversationId' => 'DiscussionID',
            'title' => 'Name',
            'channelId' => 'CategoryID',
            'memberId' => 'InsertUserID',
            'sticky' => 'Announce',
            'locked' => 'Closed',
            //'countPosts' => 'CountComments',
            'lastPostMemberId' => 'LastCommentUserID',
            'content' => 'Body',
            'startTime' => 'DateInserted',
            'lastPostTime' => 'DateLastComment',
            'Format=BBCode',
        ];
        $filters = [
            'title' => \Porter\Filter\DecodeHtml::class,
            'startTime' => \Porter\Filter\UnixtimeToDate::class,
            'lastPostTime' => \Porter\Filter\UnixtimeToDate::class,
        ];
        // The body of the OP is in the post table.
        $this->export(
            'Discussion',
            "select c.conversationId, c.title, c.channelId, p.memberId, startTime, lastPostTime,
                    c.sticky, c.locked, c.lastPostMemberId, p.content
                from :_conversation c
                left join :_post p on p.conversationId = c.conversationId
                where private = 0
                group by c.conversationId",
            $discussion_Map,
            $filters
        );
    }

    protected function comments(): void
    {
        $comment_Map = [
            'postId' => 'CommentID',
            'conversationId' => 'DiscussionID',
            'content' => 'Body',
            'memberId' => 'InsertUserID',
            'editMemberId' => 'UpdateUserID',
            'time' => 'DateInserted',
            'editTime' => 'DateUpdated',
            'Format=BBCode',
        ];
        $filters = [
            'time' => \Porter\Filter\UnixtimeToDate::class,
            'editTime' => \Porter\Filter\UnixtimeToDate::class,
        ];
        // Now we need to omit the comments we used as the OP.
        $this->export(
            'Comment',
            "select p.*
                from :_post p
                inner join :_conversation c ON c.conversationId = p.conversationId and c.private = 0
                join ( 
                    select conversationId, min(postId) as m
                    from :_post
                    group by conversationId
                ) r on r.conversationId = c.conversationId
                where p.postId <> r.m",
            $comment_Map,
            $filters
        );
    }

    protected function bookmarks(): void
    {
        $userDiscussion_Map = [
            'id' => 'UserID',
            'conversationId' => 'DiscussionID',
        ];
        $this->export(
            'UserDiscussion',
            "select *
                from :_member_conversation
                where starred = 1",
            $userDiscussion_Map
        );
    }

    protected function conversations(): void
    {
        $conversation_map = [
            'conversationId' => 'ConversationID',
            'countPosts' => 'CountMessages',
            'startMemberId' => 'InsertUserID',
            'time' => 'DateInserted',
            'lastposttime' => 'DateUpdated',
            'Format=BBCode',
        ];
        $filters = [
            'time' => \Porter\Filter\UnixtimeToDate::class,
            'lastposttime' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Conversation',
            "select p.*
                from :_post p
                inner join :_conversation c on c.conversationId = p.conversationId
                    and c.private = 1",
            $conversation_map,
            $filters
        );

        $userConversation_map = [
            'conversationId' => 'ConversationID',
            'memberId' => 'UserID',
        ];
        $this->export(
            'UserConversation',
            "select distinct a.fromMemberId as memberId, a.type, c.private, c.conversationId 
                from :_activity a
                inner join :_conversation c on c.conversationId = a.conversationId
                    and c.private = 1 and a.type = 'privateAdd'
                union all
                select distinct a.memberId as memberId, a.type, c.private, c.conversationId 
                from :_activity a
                inner join :_conversation c on c.conversationId = a.conversationId
                    and c.private = 1 and a.type = 'privateAdd'",
            $userConversation_map
        );

        $userConversationMessage_map = [
            'postId' => 'MessageID',
            'conversationId' => 'ConversationID',
            'content' => 'Body',
            'memberId' => 'InsertUserID',
            'time' => 'DateInserted',
            'Format=BBCode',
        ];
        $filters = [
            'time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'ConversationMessage',
            "select p.*
                from :_post p
                inner join :_conversation c on c.conversationId = p.conversationId and c.private = 1",
            $userConversationMessage_map,
            $filters
        );
    }
}
