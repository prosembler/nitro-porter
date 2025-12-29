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
    public const CHANNEL_TYPES = [
        0 => 'GUILD_TEXT',
        4 => 'GUILD_CATEGORY',
        5 => 'GUILD_ANNOUNCEMENT',
        11 => 'PUBLIC_THREAD',
        15 => 'GUILD_FORUM',
    ];

    /**
     * @param Migration $port
     */
    public function run(?Migration $port = null): void
    {
        $this->users($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $map = [
            'uid' => 'UserID',
            'name' => 'Name',
        ];
        $port->export(
            'User',
            "select * from discord_users",
            $map
        );
    }
}
