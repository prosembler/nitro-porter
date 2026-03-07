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

    protected const array USERROLES_STRUCTURE = [
        'user_id' => 'bigint',
        'role_id' => 'bigint',
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
        //'fileTransferSupport' => true,
        'renumberIndices' => true,  // @todo respect this flag
    ];

    /**
     * Main operation.
     */
    public function run(): void
    {
        $this->extractUserRoles();

        $this->users();
        $this->roles();
        $this->categories();
        $this->discussions();
        $this->comments();
    }

    /**
     * Generate intermediary table to unpack user role associations before 'normal' export.
     */
    public function extractUserRoles(): void
    {
        $start = microtime(true);
        $info = [];
        $this->porterStorage->prepare('discord_user_roles', self::USERROLES_STRUCTURE);

        $users = $this->sourceQB()->from('discord_users')->get(['id', 'roles'])->toArray();
        foreach ($users as $user) {
            $roles = json_decode($user->roles); // Discord's array got auto-collapsed to JSON.
            foreach ($roles as $roleID) {
                $row = ['user_id' => $user->id, 'role_id' => $roleID];
                $info = $this->porterStorage->stream($row, self::USERROLES_STRUCTURE, $info);
            }
        }
        $this->porterStorage->stream([], [], $info, true);
        Log::storage('extract', 'discord_user_roles', (microtime(true) - $start), $info['rows'], $info['memory'] ?? 0);
    }

    /**
     */
    protected function users(): void
    {
        $map = [
            'id' => 'UserID',
            'derived_name' => 'Name', // prefer 1) nick 2) global_name 3) username
        ];
        $query = $this->sourceQB()->from('discord_users')
            ->select()
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
            ->select();
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
            ])->select();
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
        $query = $this->sourceQB()->from('discord_channels')
            ->whereIn('type', [
                self::CHANNEL_TYPE['PUBLIC_THREAD'],
                self::CHANNEL_TYPE['GUILD_ANNOUNCEMENT']
            ])
            ->select()
            ->selectRaw('id as parent_id');
        // Fold text channels into being both category + the discussion within it.
        $textChannels = $this->sourceQB()->from('discord_channels')
            ->where('type', '=', self::CHANNEL_TYPE['GUILD_TEXT'])
            ->select()
            ->selectRaw('id as parent_id');
        $query->union($textChannels);
        $this->export('Discussion', $query, $map);
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
            ->select()
            ->selectRaw('from_unixtime(timestamp) as DateInserted')
            ->selectRaw('from_unixtime(edited_timestamp) as DateUpdated');
        $this->export('Comment', $query, $map);
    }
}
