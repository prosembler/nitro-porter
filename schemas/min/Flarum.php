<?php
/** Tables & columns REQUIRED for Source\Flarum to run. */

return [
    'discussions' => [
        'id',
        'user_id',
        'title',
        'slug',
        'created_at',
        'first_post_id',
        'last_post_id',
        'last_posted_at',
        'last_posted_user_id',
        'post_number_index',
        'is_locked',
    ],
    'discussion_tag' => [],
    'groups' => [],
    'group_user' => [],
    'posts' => [],
    'tags' => [],
    'users' => [],
];
