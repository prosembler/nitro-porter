<?php

/**
 *
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

class Flarum extends Source
{
    public const array INFO = [
        'name' => 'Flarum',
        'defaultTablePrefix' => 'FLA_',
        'charsetTable' => 'posts',
    ];

    public const array FEATURE_REQUIREMENTS = [
        'conversations' => [
            'enabled' => 'fof/byobu',
            'schema' => ['recipients' => [], 'discussions' => ['is_private'],]
        ],
        'bookmarks' => ['enabled' => 'flarum/subscriptions'],
        'badges' => ['enabled' => '17development/flarum-user-badges'],
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = [
        'discussions' => [],
        'groups' => [],
        'posts' => [],
        'tags' => [],
        'users' => [],
    ];

    protected function users(): void
    {
        $map = [
            'id' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            'password' => 'Password',
            'joined_at' => 'DateInserted',
            'last_seen_at' => 'DateLastActive',
            'is_email_confirmed' => 'Confirmed',
            'discussion_count' => 'CountDiscussions',
            'comment_count' => 'CountComments',
        ];
        $filters = [
            'username' => \Porter\Filter\DecodeHtml::class,
        ];
        $this->export('User', "select *, 'phpass' as HashMethod from :_users", $map, $filters);
    }

    protected function roles(): void
    {
        $map = [
            'id' => 'RoleID',
            'name_singular' => 'Name',
        ];
        $this->export('Role', "select * from `:_groups`", $map);

        // User Role.
        $map = [
            'user_id' => 'UserID',
            'group_id' => 'RoleID',
        ];
        $this->export('UserRole', "select * from :_group_user", $map);
    }

    protected function categories(): void
    {
        $map = [
            'id' => 'CategoryID',
            'name' => 'Name',
            'slug' => 'UrlCode',
            'description' => 'Description',
            'parent_id' => 'ParentCategoryID',
            'position' => 'Sort',
            'discussion_count' => 'CountDiscussions',
        ];
        $this->export('Category', "select * from :_tags", $map);
    }

    protected function discussions(): void
    {
        $map = [
            'id' => 'DiscussionID',
            'user_id' => 'InsertUserID',
            'title' => 'Name',
            'is_sticky' => 'Announce', // flarum/sticky — optional field
            'is_locked' => 'Closed', // flarum/lock — optional field
        ];
        $filters = [
            'title' => \Porter\Filter\DecodeHtml::class,
        ];

        // Put the OP in the body.
        $getBody = '';
        $joinPosts = '';
        if ($this->getDiscussionBodyMode()) {
            $getBody = 'p.content as Body,';
            $joinPosts = 'join :_posts p on p.id = d.first_post_id';
        }
        $this->export(
            'Discussion',
            "select d.*, $getBody min(dt.tag_id) as CategoryID
                 from :_discussions d
                 $joinPosts
                 join :_discussion_tag dt on dt.discussion_id = d.id
                 where d.is_private <> 1
                 group by d.id",
            $map,
            $filters
        );
    }

    protected function bookmarks(): void
    {
        if (!$this->hasInputSchema('discussion_user', ['subscription'])) {
            return;
        }
        $map = [
            'discussion_id' => 'DiscussionID',
            'user_id' => 'InsertUserID',
            'last_read_at' => 'DateLastViewed',
        ];
        $query = "select *, if (subscription = 'follow', 1, 0) as Bookmarked from :_discussion_user";
        $this->export('UserDiscussion', $query, $map);
    }

    protected function comments(): void
    {
        $comment_Map = [
            'id' => 'CommentID',
            'discussion_id' => 'DiscussionID',
            'user_id' => 'InsertUserID',
            'created_at' => 'DateInserted',
            'edited_at' => 'DateUpdated',
            'edited_user_id' => 'UpdateUserID',
            'content' => 'Body',
        ];

        // Skip the OP.
        $skipOP = '';
        if ($this->getDiscussionBodyMode()) {
            $skipOP = 'and `number` > 1';
        }
        $this->export(
            'Comment',
            "select *, 'Html' as Format
                from :_posts
                where type = 'comment'
                    $skipOP",
            $comment_Map
        );
    }

    protected function badges(): void
    {
        if (!$this->hasInputSchema('badges')) {
            return;
        }
        $map = [
            'discussion_id' => 'BadgeID',
            'user_id' => 'InsertUserID',
            'last_read_at' => 'DateLastViewed',
            'is_visible' => 'Visible',
        ];
        $query = "select * from :_badges";
        $this->export('Badge', $query, $map);

        // User Badges
        $map = [
            'badge_id' => 'BadgeID',
            'user_id' => 'UserID',
            'description' => 'Reason',
            'assigned_at' => 'DateCompleted',
        ];
        $query = "select * from :_badge_user";
        $this->export('UserBadge', $query, $map);
    }

    protected function conversations(): void
    {
        if (!$this->hasInputSchema('recipients')) {
            return;
        }
        // Messages
        $map = [
            'discussion_id' => 'ConversationID',
            'content' => 'Body',
        ];
        $query = "select *
            from :_posts p
            left join :_discussions d on d.id = p.discussion_id
            where d.is_private = 1";
        $this->export('ConversationMessage', $query, $map);

        // Conversations
        $map = [
            'discussion_id' => 'ConversationID',
            'user_id' => 'InsertUserID',
            'title' => 'Subject',
        ];
        $query = "select * from :_discussions where is_private = 1";
        $this->export('Conversation', $query, $map);

        // Recipients
        $map = [
            'discussion_id' => 'ConversationID',
            'user_id' => 'UserID',
        ];
        $query = "select * from :_recipients";
        $this->export('UserConversation', $query, $map);
    }
}
