<?php

return [
    'users' => [
        'id' => 'int',
        'username' => 'varchar(100)',
        'email' => 'varchar(100)',
        'is_email_confirmed' => 'tinyint',
        'password' => 'varchar(100)',
        'avatar_url' => 'varchar(100)',
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'discussion_count' => 'int',
        'comment_count' => 'int',
    ],
    'groups' => [
        'id' => 'int',
        'name_singular' => 'varchar(100)',
        'name_plural' => 'varchar(100)',
        'color' => 'varchar(20)',
        'icon' => 'varchar(100)',
        'is_hidden' => 'tinyint',
    ],
    'group_user' => [
        'user_id' => 'int',
        'group_id' => 'int',
    ],
    'tags' => [
        'id' => 'int',
        'name' => 'varchar(100)',
        'slug' => 'varchar(100)',
        'description' => 'text',
        'parent_id' => 'int',
        'position' => 'int',
        'discussion_count' => 'int',
        'is_hidden' => 'tinyint',
        'is_restricted' => 'tinyint',
    ],
    'posts' => [ // @see \Porter\Postscript\Flarum::numberPosts() for 'keys' requirement.
        'id' => 'int',
        'discussion_id' => 'int',
        'user_id' => 'int',
        'created_at' => 'datetime',
        'edited_at' => 'datetime',
        'edited_user_id' => 'int',
        'type' => 'varchar(100)',
        'content' => 'longText',
        'number' => 'int',
        'keys' => [
            'FLA_posts_discussion_id_number_unique' => [
                'type' => 'unique',
                'columns' => ['discussion_id', 'number'],
            ],
            'FLA_posts_id_primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ]
        ],
    ],
    'discussions' => [ // @see \Porter\Postscript\Flarum::numberPosts() for 'keys' requirement.
        'id' => 'int',
        'user_id' => 'int',
        'title' => 'varchar(200)',
        'slug' => 'varchar(200)',
        'created_at' => 'datetime',
        'first_post_id' => 'int',
        'last_post_id' => 'int',
        'last_posted_at' => 'datetime',
        'last_posted_user_id' => 'int',
        'post_number_index' => 'int',
        'is_private' => 'tinyint', // fof/byobu (PMs)
        'is_sticky' => 'tinyint', // flarum/sticky
        'is_locked' => 'tinyint', // flarum/lock
        //'votes' => 'int', // fof/polls
        //'hotness' => 'double', // fof/gamification
        //'view_count' => 'int', // flarumite/simple-discussion-views
        //'best_answer_notified' => 'tinyint', // fof/best-answer
        'keys' => [
            'FLA_discussions_id_primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ]
        ],
    ],
    'discussion_tag' => [
        'discussion_id' => 'int',
        'tag_id' => 'int',
    ],
    'discussion_user' => [
        'discussion_id' => 'int',
        'user_id' => 'int',
        'last_read_at' => 'datetime',
        'subscription' => [null, 'follow', 'ignore'],
        'last_read_post_number' => 'int',
        'keys' => [
            'FLA_discussion_user_discussion_id_foreign' => [
                'type' => 'index',
                'columns' => ['discussion_id'],
            ],
        ],
    ],
    'fof_upload_files' => [
        'id' => 'int',
        'actor_id' => 'int',
        'discussion_id' => 'int',
        'post_id' => 'int',
        'base_name' => 'varchar(255)', // "download as"
        'path' => 'varchar(255)', // from /forumroot/assets/files
        'url' => 'varchar(255)',
        'type' => 'varchar(255)', // MIME
        'size'  => 'int', // bytes
        'created_at' => 'datetime',
        'upload_method' => 'varchar(255)', // Probably just 'local'
        'tag' => 'varchar(255)', // Required; generates preview in Profile -> "My Media"
    ],
    'badges' => [
        'id' => 'int',
        'name' => 'varchar(200)',
        'image' => 'text',
        'description' => 'text',
        'badge_category_id' => 'int',
        'points' => 'int',
        'created_at' => 'datetime',
        'is_visible' => 'tinyint',
    ],
    'badge_user' => [
        'badge_id' => 'int',
        'user_id' => 'int',
        'assigned_at' => 'datetime',
        'description' => 'text',
    ],
    'reactions' => [
        'id' => 'int',
        'identifier' => 'varchar(200)',
        'type' => 'varchar(200)',
        'enabled' => 'tinyint',
        'display' => 'varchar(200)',
    ],
    'post_reactions' => [
        'id' => 'int',
        'post_id' => 'int',
        'user_id' => 'int',
        'reaction_id' => 'int',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ],
    'polls' => [
        'id' => 'int',
        'question' => 'varchar(200)',
        'discussion_id' => 'int',
        'post_id' => 'int',
        'user_id' => 'int',
        'public_poll' => 'tinyint', // Map to "Anonymous" somehow?
        'end_date' => 'datetime', // Using date created here will close all polls, but work fine.
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'vote_count' => 'int',
        'settings' => 'text',
    ],
    'poll_options' => [
        'id' => 'int',
        'answer' => 'varchar(200)',
        'poll_id' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'vote_count' => 'int',
    ],
    'poll_votes' => [
        //id
        'poll_id' => 'int',
        'option_id' => 'int',
        'user_id' => 'int',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ],
];
