<?php

/**
 * Discord exporter tool
 *
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

/**
 * @see \Porter\Origin\Discord
 */
class Discord extends Source
{
    public const array SUPPORTED = [
        'name' => 'Discord',
        'defaultTablePrefix' => '',
        'features' => [
            'Users' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Roles' => 1,
            'Avatars' => 1,
            'Attachments' => 1,
            'Emoji' => 1,
            'Reactions' => 0, // No Origin support yet — requires separate calls
            'Polls' => 0, // No Origin support yet — requires inline unpacking
        ]
    ];

    public const int DISCORD_EPOCH_DIFF = 1288834974657;

    public const array CHANNEL_TYPE = [
        'GUILD_TEXT' => 0,
        'GUILD_CATEGORY' => 4,
        'GUILD_ANNOUNCEMENT' => 5,
        'PUBLIC_THREAD' => 11,
        'GUILD_FORUM' => 15,
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
        'fileTransferSupport' => true,
        'renumberIndices' => true,
    ];

    protected function users(): void
    {
        $map = [
            'id' => 'UserID',
            'derived_name' => 'Name', // prefer 1) nick 2) global_name 3) username
            'derived_avatar' => 'Photo', // prefer guild-specific 'avatar' to 'global_avatar'
            'joined_at' => 'DateInserted', // Guild-specific date
        ];
        $query = $this->sourceQB()->from('discord_users')->select('discord_users.*')
            ->selectRaw('COALESCE(nick, COALESCE(global_name, username)) as derived_name')
            ->selectRaw('COALESCE(avatar, global_avatar) as derived_avatar');
        $this->export('User', $query, $map);
    }

    protected function roles(): void
    {
        $map = [
            'id' => 'RoleID',
            'name' => 'Name',
            //position, managed, mentionable
        ];
        $query = $this->sourceQB()->from('discord_roles')->distinct('id')->select();
        $this->export('Role', $query, $map);

        // UserRoles
        $map = [
            'user_id' => 'UserID',
            'role_id' => 'RoleID',
        ];
        $query = $this->sourceQB()->from('discord_user_roles')->select('discord_user_roles.*');
        $this->export('UserRole', $query, $map);
    }

    protected function categories(): void
    {
        $map = [
            'id' => 'CategoryID',
            'name' => 'Name',
            'parent_id' => 'ParentCategoryID',
            'position' => 'Sort',
            'topic' => 'Description',
            'last_message_id' => 'LastCommentID',
        ];
        $query = $this->sourceQB()->from('discord_channels')->select('discord_channels.*')
            ->whereIn('type', [
                self::CHANNEL_TYPE['GUILD_CATEGORY'],
                self::CHANNEL_TYPE['GUILD_FORUM'],
                self::CHANNEL_TYPE['GUILD_TEXT']
            ]);
        $this->export('Category', $query, $map);
    }

    protected function discussions(): void
    {
        $map = [
            'id' => 'DiscussionID',
            'name' => 'Name',
            'parent_id' => 'CategoryID',
            'owner_id' => 'InsertUserID',
            'last_message_id' => 'LastCommentID',
            'message_count' => 'LastCommentID',
            'derived_timestamp' => 'DateInserted',
        ];
        $filters = [
            'parent_id' => fn($val, $col, $row) // Text channels use 'id' as 'parent_id' — they are their own category.
                => (Discord::CHANNEL_TYPE['GUILD_TEXT'] === $row['type']) ? $row['id'] : $row['parent_id'],
            'derived_timestamp' => __NAMESPACE__ . '\Discord::timestampFromSnowflake',
        ];
        $query = $this->sourceQB()->from('discord_channels')->select('discord_channels.*')
            ->selectRaw('id as derived_timestamp')
            ->whereIn('type', [
                self::CHANNEL_TYPE['PUBLIC_THREAD'],
                self::CHANNEL_TYPE['GUILD_ANNOUNCEMENT'],
                self::CHANNEL_TYPE['GUILD_TEXT']
            ]);
        $this->export('Discussion', $query, $map, $filters);
    }

    protected function comments(): void
    {
        $map = [
            'id' => 'CommentID',
            'content' => 'Body',
            'channel_id' => 'DiscussionID',
            'authorid' => 'InsertUserID',
            'pinned' => 'Announce',
            //'embeds' => '',
                // [{"type":"link","url":"http:\/\/www.example.com","description":"Your source for video game news..."}]
        ];
        $query = $this->sourceQB()->from('discord_messages')->select('discord_messages.*')
            ->selectRaw('timestamp(timestamp) as DateInserted')
            ->selectRaw('timestamp(edited_timestamp) as DateUpdated');
        $this->export('Comment', $query, $map);
    }

    protected function attachments(): void
    {
        $map = [
            'id' => 'MediaID',
            'message_id' => 'ForeignID',
            'filename' => 'Name',
            'width' => 'ImageWidth',
            'height' => 'ImageHeight',
            'size' => 'Size',
            'content_type' => 'Type',
            'download_path' => 'SourceFullPath',
        ];
        $query = $this->sourceQB()->from('discord_attachments')->select('discord_attachments.*');
        $this->export('Media', $query, $map);
    }

    protected function emojis(): void
    {
        $map = [
            'id' => 'EmojiID',
            'name' => 'Name',
            'animated' => 'Animated',
            'user.id' => 'InsertUserID',
        ];
        $query = $this->sourceQB()->from('discord_emojis')->select('discord_emojis.*');
        $this->export('Emoji', $query, $map);
    }

    /**
     * Discord SnowflakeIDs have timestamps embedded within them.
     *
     * @param mixed $value A Discord SnowflakeID
     * @return int|null Unix timestamp
     */
    protected function timestampFromSnowflake(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }
        $timestamp = substr(decbin((int) $value), 0, -22);
        return bindec($timestamp) + self::DISCORD_EPOCH_DIFF;
    }
}
