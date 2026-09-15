<?php

return [
    'users' => [
        'new_id' => 'increments',
        'nick' => 'varchar(100)',
        'avatar' => 'varchar(100)',
        'roles' => 'text',
        'joined_at' => 'datetime',
        'premium_since' => 'datetime',
        // Under 'user' object
        'id' => 'bigint',
        'username' => 'varchar(100)',
        'discriminator' => 'varchar(100)',
        'global_name' => 'varchar(100)',
        'global_avatar' => 'varchar(100)',
        'email' => 'varchar(100)',
        'bot' => 'tinyint',
        'verified' => 'tinyint',
        'keys' => [
            'discord_users_id_index' => [
                'type' => 'unique',
                'columns' => ['id'],
            ]
        ],
    ],
    'reactions' => [
        'message_id' => 'bigint',
        'emoji_id' => 'bigint',
        'emoji_name' => 'varchar(100)',
        'count' => 'int',
        'keys' => [
            'discord_reactions_index' => [
                'type' => 'unique',
                'columns' => ['message_id', 'emoji_id', 'emoji_name'],
            ]
        ],
    ],
    'user_reactions' => [
        'message_id' => 'bigint',
        'emoji_id' => 'bigint',
        'user_id' => 'bigint',
        'emoji_name' => 'varchar(100)',
        'keys' => [
            'discord_user_reactions_index' => [
                'type' => 'unique',
                'columns' => ['message_id', 'user_id', 'emoji_id', 'emoji_name'],
            ]
        ],
    ],
    'roles' => [
        'new_id' => 'increments',
        'id' => 'bigint',
        'name' => 'varchar(100)',
        'position' => 'int',
        'managed' => 'tinyint',
        'mentionable' => 'tinyint',
        'keys' => [
            'discord_roles_id_index' => [
                'type' => 'unique',
                'columns' => ['id'],
            ]
        ],
    ],
    'emojis' => [
        'new_id' => 'increments',
        'id' => 'bigint',
        'name' => 'varchar(100)',
        'user' => 'text', // author
        'animated' => 'tinyint',
        'keys' => [
            'discord_emojis_id_index' => [
                'type' => 'unique',
                'columns' => ['id'],
            ]
        ],
    ],
    'polls' => [
        'new_id' => 'increments',
        'id' => 'bigint',
        'is_final' => 'tinyint',
        'question' => 'text',
        'emoji' => 'bigint',
        'expiry' => 'datetime',
        'allow_multiselect' => 'tinyint',
        'keys' => [
            'discord_polls_id_index' => [
                'type' => 'unique',
                'columns' => ['id'],
            ]
        ],
    ],
    'poll_answers' => [
        'new_id' => 'increments',
        'poll_id' => 'bigint',
        'answer_id' => 'bigint', // Non-unique.
        'count' => 'int',
        'emoji_id' => 'bigint',
        'text' => 'text',
        'keys' => [
            'discord_answers_index' => [
                'type' => 'unique',
                'columns' => ['poll_id', 'answer_id'],
            ]
        ],
    ],
    'poll_user_answers' => [
        'poll_id' => 'bigint',
        'answer_id' => 'bigint',
        'user_id' => 'bigint',
        'keys' => [
            'discord_user_answers_index' => [
                'type' => 'unique',
                'columns' => ['poll_id', 'answer_id', 'user_id'],
            ]
        ],
    ],
    'channels' => [
        'new_id' => 'increments',
        'id' => 'bigint',
        'type' => 'int', //@todo key?
        'guild_id' => 'bigint',
        'position' => 'varchar(100)',
        'name' => 'text',
        'topic' => 'text',
        'last_message_id' => 'bigint',
        'parent_id' => 'bigint',
        'message_count' => 'int',
        // thread-only
        'owner_id' => 'bigint',
        'member_count' => 'int',
        'thread_metadata' => 'text',
        'keys' => [
            'discord_channels_id_index' => [
                'type' => 'unique',
                'columns' => ['id'],
            ]
        ],
    ],
    'messages' => [
        'new_id' => 'increments',
        'id' => 'bigint',
        'channel_id' => 'bigint',
        'content' => 'text',
        'timestamp' => 'datetime',
        'edited_timestamp' => 'datetime',
        'pinned' => 'tinyint',
        'type' => 'int',
        // OBJECTS
        'referenced_message' => 'text',
        'message_reference' => 'text',
        'thread' => 'text',
        'author' => 'text',
        'authorid' => 'bigint', // Derived from author.id — flattened to allow Source to filter.
        // OBJECTS[]
        'poll' => 'text', // @see https://discord.com/developers/docs/resources/poll#poll-object
        'attachments' => 'text', // @see https://discord.com/developers/docs/resources/message#attachment-object
        'embeds' => 'text', // @see https://discord.com/developers/docs/resources/message#embed-object
        'reactions' => 'text', // @see https://discord.com/developers/docs/resources/message#reaction-object
        'sticker_items' => 'text',
        'mentions' => 'text',
        'mention_roles' => 'text',
        'mention_channels' => 'text',
        'keys' => [
            //  Index any keys that may require renumbering (for auto-joins).
            'discord_messages_id_index' => [
                'type' => 'unique',
                'columns' => ['id'],
            ],
            // Covering index for resuming message pulls [select id where channel_id=x order by timestamp].
            'discord_messages_resuming_index' => [
                'type' => 'index',
                'columns' => ['channel_id', 'timestamp'], // 'id' (pk) is implicitly in index
            ]
        ],
    ],
    'user_roles' => [
        'user_id' => 'bigint',
        'role_id' => 'bigint',
    ],
    'attachments' => [
        'new_id' => 'increments',
        'id' => 'bigint',
        'message_id' => 'bigint',
        'filename' => 'text',
        'url' => 'text',
        'size' => 'bigint',
        'width' => 'int',
        'height' => 'int',
        'content_type' => 'varchar(100)',
        'download_path' => 'text', // where we put the file; not in Discord's response
        'keys' => [
            'discord_attachments_id_index' => [
                'type' => 'unique',
                'columns' => ['id'],
            ]
        ],
    ],
];
