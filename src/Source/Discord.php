<?php

/**
 * Discord exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Migration;
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
        'hasSnowflakeIDs' => true,  // @todo respect this flag
    ];

    /**
     * @param Migration|null $port
     */
    public function run(?Migration $port = null): void
    {
        $this->extractUserRoles();

        $this->users($port);
        $this->roles($port);
        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
    }

    /**
     * Generate intermediary table to unpack user role associations before 'normal' export.
     */
    public function extractUserRoles(): void
    {
        $start = microtime(true);
        $info = [];
        $this->porterStorage->prepare('discord_user_roles', self::USERROLES_STRUCTURE);

        $users = $this->inputQB()->from('discord_users')->get(['id', 'roles'])->toArray();
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
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $map = [
            'id' => 'UserID',
            'derived_name' => 'Name', // prefer 1) nick 2) global_name 3) username
        ];
        $filter = ['id' => 'force32bit']; // renumber Snowflake #yolo
        $query = $port->sourceQB()->from('discord_users')
            ->select()
            ->selectRaw('COALESCE(nick, COALESCE(global_name, username)) as derived_name');
        $port->export('User', $query, $map, $filter);
    }

    protected function roles(Migration $port): void
    {
        // Roles
        $map = [
            'id' => 'RoleID',
            'name' => 'Name',
        ];
        $filter = ['id' => 'force32bit'];
        $query = $this->inputQB()->from('discord_roles')
            ->distinct('id')
            ->select();
        $port->export('Role', $query, $map, $filter);

        // UserRoles
        $map = [
            'user_id' => 'UserID',
            'role_id' => 'RoleID',
        ];
        $filter = [
            'user_id' => 'force32bit',
            'role_id' => 'force32bit',
        ];
        $query = $this->inputQB()->from('discord_user_roles') // Intermediary table not from Origin.
            ->select();
        $port->export('UserRole', $query, $map, $filter);
    }

    protected function categories(Migration $port): void
    {
        $map = [
            'id' => 'CategoryID',
            'name' => 'Name',
        ];
        $filter = ['id' => 'force32bit'];
        $query = $port->sourceQB()->from('discord_channels')
            ->whereIn('type', [
                self::CHANNEL_TYPE['GUILD_CATEGORY'],
                self::CHANNEL_TYPE['GUILD_FORUM'],
                self::CHANNEL_TYPE['GUILD_TEXT']
            ])->select();
        $port->export('Category', $query, $map, $filter);
    }

    protected function discussions(Migration $port): void
    {
        $map = [
            'id' => 'DiscussionID',
            'name' => 'Name',
            'parent_id' => 'CategoryID',
            'owner_id' => 'InsertUserID',
        ];
        $filter = [
            'id' => 'force32bit',
            'parent_id' => 'force32bit',
            'owner_id' => 'force32bit',
        ];
        $query = $port->sourceQB()->from('discord_channels')
            ->whereIn('type', [
                self::CHANNEL_TYPE['PUBLIC_THREAD'],
                self::CHANNEL_TYPE['GUILD_ANNOUNCEMENT']
            ])
            ->select()
            ->selectRaw('id as parent_id');
        // Fold text channels into being both category + the discussion within it.
        $textChannels = $port->sourceQB()->from('discord_channels')
            ->where('type', '=', self::CHANNEL_TYPE['GUILD_TEXT'])
            ->select()
            ->selectRaw('id as parent_id');
        $query->union($textChannels);
        $port->export('Discussion', $query, $map, $filter);
    }

    protected function comments(Migration $port): void
    {
        $map = [
            'id' => 'CommentID',
            'content' => 'Body',
            'channel_id' => 'DiscussionID',
            'authorid' => 'InsertUserID',
        ];
        $filter = [
            'id' => 'force32bit',
            'channel_id' => 'force32bit',
            'authorid' => 'force32bit',
        ];
        $query = $port->sourceQB()->from('discord_messages')
            ->select()
            ->selectRaw('from_unixtime(timestamp) as DateInserted')
            ->selectRaw('from_unixtime(edited_timestamp) as DateUpdated');
        $port->export('Comment', $query, $map, $filter);
    }

    public function validate(): void
    {
        // @todo Check if kludged SnowflakeIDs caused duplication in future primary keys.
    }
}
