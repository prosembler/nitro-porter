<?php

/**
 * Discord exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
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
            // @todo
            'Avatars' => 0,
            'Attachments' => 0,
            'Polls' => 0,
            'Reactions' => 0,
            // Not possible.
            'Passwords' => 0,
            'AvatarThumbnails' => 0,
            'PrivateMessages' => 0,
            'Signatures' => 0,
            'Bookmarks' => 0,
        ]
    ];

    public const array CHANNEL_TYPE = [
        'GUILD_TEXT' => 0,
        'GUILD_CATEGORY' => 4,
        'GUILD_ANNOUNCEMENT' => 5,
        'PUBLIC_THREAD' => 11,
        'GUILD_FORUM' => 15,
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
        //'fileTransferSupport' => true,
        'renumberIndices' => true,  // @todo respect this flag
    ];

    protected function users(): void
    {
        $map = [
            'id' => 'UserID',
            'derived_name' => 'Name', // prefer 1) nick 2) global_name 3) username
        ];
        $query = $this->sourceQB()->from('discord_users')
            ->select('discord_users.*')
            ->selectRaw('COALESCE(nick, COALESCE(global_name, username)) as derived_name');
        $this->export('User', $query, $map);
    }

    protected function roles(): void
    {
        // Roles
        $map = [
            'id' => 'RoleID',
            'name' => 'Name',
        ];
        $query = $this->sourceQB()->from('discord_roles')
            ->distinct('id')
            ->select();
        $this->export('Role', $query, $map);

        // UserRoles
        $map = [
            'user_id' => 'UserID',
            'role_id' => 'RoleID',
        ];
        $query = $this->sourceQB()->from('discord_user_roles') // Intermediary table not from Origin.
            ->select('discord_user_roles.*');
        $this->export('UserRole', $query, $map);
    }

    protected function categories(): void
    {
        $map = [
            'id' => 'CategoryID',
            'name' => 'Name',
        ];
        $query = $this->sourceQB()->from('discord_channels')
            ->whereIn('type', [
                self::CHANNEL_TYPE['GUILD_CATEGORY'],
                self::CHANNEL_TYPE['GUILD_FORUM'],
                self::CHANNEL_TYPE['GUILD_TEXT']
            ])->select('discord_channels.*');
        $this->export('Category', $query, $map);
    }

    protected function discussions(): void
    {
        $map = [
            'id' => 'DiscussionID',
            'name' => 'Name',
            'parent_id' => 'CategoryID',
            'owner_id' => 'InsertUserID',
        ];
        $filters = [
            'parent_id' => fn($val, $col, $row) // Text channels use 'id' as 'parent_id' — they are their own category.
                => (Discord::CHANNEL_TYPE['GUILD_TEXT'] === $row['type']) ? $row['id'] : $row['parent_id']
        ];
        $query = $this->sourceQB()->from('discord_channels')
            ->whereIn('type', [
                self::CHANNEL_TYPE['PUBLIC_THREAD'],
                self::CHANNEL_TYPE['GUILD_ANNOUNCEMENT'],
                self::CHANNEL_TYPE['GUILD_TEXT']
            ])
            ->select('discord_channels.*');
        $this->export('Discussion', $query, $map, $filters);
    }

    protected function comments(): void
    {
        $map = [
            'id' => 'CommentID',
            'content' => 'Body',
            'channel_id' => 'DiscussionID',
            'authorid' => 'InsertUserID',
        ];
        $query = $this->sourceQB()->from('discord_messages')
            ->select('discord_messages.*')
            ->selectRaw('timestamp(timestamp) as DateInserted')
            ->selectRaw('timestamp(edited_timestamp) as DateUpdated');
        $this->export('Comment', $query, $map);
    }
}
