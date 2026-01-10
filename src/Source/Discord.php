<?php

/**
 * Discord exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Migration;
use Porter\Source;

/**
 * @see \Porter\Origin\Discord
 */
class Discord extends Source
{
    public const CHANNEL_TYPE = [
        'GUILD_TEXT' => 0,
        'GUILD_CATEGORY' => 4,
        'GUILD_ANNOUNCEMENT' => 5,
        'PUBLIC_THREAD' => 11,
        'GUILD_FORUM' => 15,
    ];

    /**
     * @param Migration|null $port
     */
    public function run(?Migration $port = null): void
    {
        $this->users($port);
        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $map = [
            'id' => 'UserID',
            'name' => 'Name',
        ];
        $query = $port->sourceQB()->from('discord_users')
            ->select();
        $port->export('User', $query, $map);
    }

    protected function categories(Migration $port): void
    {
        $map = [
            'id' => 'CategoryID',
            'name' => 'Name',
        ];
        $query = $port->sourceQB()->from('discord_channels')
            ->where('type', '=', self::CHANNEL_TYPE['GUILD_FORUM'])
            ->select();
        $port->export('Category', $query, $map);
    }

    protected function discussions(Migration $port): void
    {
        $map = [
            'id' => 'DiscussionID',
            'name' => 'Name',
        ];
        $query = $port->sourceQB()->from('discord_channels')
            ->where('type', '=', self::CHANNEL_TYPE['PUBLIC_THREAD'])
            ->select();
        $port->export('Discussion', $query, $map);
    }

    protected function comments(Migration $port): void
    {
        $map = [
            'id' => 'CommentID',
        ];
        $query = $port->sourceQB()->from('discord_messages')
            ->select();
        $port->export('Comment', $query, $map);
    }
}
