<?php

/** Tables & columns REQUIRED for Source\PhpBb3 to run. */

return [
    'users' => ['user_id', 'group_id', 'username', 'user_password', 'user_email',
        'user_timezone', 'user_posts', 'user_regdate', 'user_lastvisit',
        'user_avatar', 'user_avatar_type', 'user_sig', 'user_sig_bbcode_uid',],
    'groups' => ['group_id', 'group_name', 'group_desc'],
    'user_group' => ['user_id', 'group_id'],
    'forums' => ['forum_id', 'forum_name', 'forum_desc', 'left_id', 'parent_id'],
    'topics' => ['topic_id', 'forum_id', 'topic_poster', 'topic_title', 'topic_views',
        'topic_first_post_id', 'topic_status', 'topic_type', 'topic_time', 'topic_last_post_time',],
    'posts' => ['post_id', 'topic_id', 'post_text', 'poster_id', 'post_edit_user', 'post_time', 'post_edit_time'],
    'attachments' => ['attach_id', 'topic_id', 'post_msg_id', 'real_filename', 'poster_id', 'mimetype',
        'filesize', 'filetime', 'extension', 'physical_filename',],
    'privmsgs' => ['msg_id', 'author_id','message_subject',],
    'privmsgs_to' => ['msg_id', 'author_id', 'user_id',],
    'poll_options' => ['poll_id', 'poll_title', 'topic_id', 'topic_time', 'topic_poster', 'poll_option_id',],
    'poll_votes' => ['vote_user_id', 'id', 'poll_option_id', 'topic_id',],
    'ranks' => ['rank_id', 'level', 'rank_title', 'rank_special', 'rank_min',],
    'bookmarks' => ['user_id', 'topic_id'],
    'topics_track' => ['forum_id', 'topic_id', 'user_id', 'mark_time',],
    'config' => ['config_name', 'config_value',],
    'banlist' => ['ban_userid',],
    'log' => ['log_id', 'user_id', 'reportee_id', 'log_ip', 'log_time', 'log_operation', 'log_data',],
];
