<?php

/**
 * Discord API tool
 *
 * The Developer ToS is silent on backups/scraping, as are the Community Guidelines. In the Developer Policy:
 *  > Do not mine or scrape any data, content, or information available on or through Discord services
 *  > (as defined in our Terms of Service).
 * @see https://support-dev.discord.com/hc/en-us/articles/8563934450327-Discord-Developer-Policy (retrieved Dec 2025)
 *
 * There is no mention of 'mine' or 'mining' in the general ToS, but it mentions scraping once:
 *  > Don’t use the services to do harm to Discord. Among other things, this includes [...]
 *  > scraping our services without our written consent, including by using any robot, spider, crawler, scraper,
 *  > or other automatic device, process, or software; [...]
 * @see https://discord.com/terms#9 (retrieved Dec 2025)
 *
 * This line is ambiguous in the context of a backup/migration of a single server (vs "our services" broadly)
 * However, all the surrounding cases are about clear abuse. Therefore, I feel comfortable asserting that
 * it is NOT a breach of the ToS to use automation for that purpose in the context of a server you "own,"
 * provided you respect rate limits, don't impersonate a user, & don't use the data for other purposes.
 * However, I am not a lawyer, and make no guarantees on permissible use of Discord or its claims otherwise.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Origin;

use Porter\Log;
use Porter\Migration;
use Porter\Origin;

/**
 * A Discord server is referred to as a 'guild' in the API docs.
 * 429 response code will have Retry-After header & retry_after in JSON body.
 * @see https://discord.com/developers/docs/reference
 */
class Discord extends Origin
{
    public const array SUPPORTED = [
        'name' => 'Discord',
    ];

    public const int MIN_MICROSECONDS = 20000; // Wait 20 milliseconds (1/50 sec) to force rate limit compliance.

    /** @var array  */
    protected const array DB_STRUCTURE_USERS = [
        'id' => 'varchar(100)', //@todo key
        'username' => 'varchar(100)',
        'discriminator' => 'varchar(100)',
        'global_name' => 'varchar(100)',
        'email' => 'varchar(100)',
        'avatar' => 'varchar(100)',
        'bot' => 'tinyint',
        'verified' => 'tinyint',
    ];

    /** @var array|string[]  */
    protected const array DB_STRUCTURE_CHANNELS = [
        'id' => 'varchar(100)', //@todo key
        'type' => 'int', //@todo key
        'guild_id' => 'varchar(100)',
        'position' => 'varchar(100)',
        'name' => 'text',
        'topic' => 'text',
        'last_message_id' => 'varchar(100)',
        'parent_id' => 'varchar(100)',
        'message_count' => 'int',
        // thread-only
        'owner_id' => 'varchar(100)',
        'member_count' => 'int',
        'thread_metadata' => 'text',
    ];

    /** @var array  */
    protected const array DB_STRUCTURE_MESSAGES = [
        'id' => 'varchar(100)', //@todo key
        'channel_id' => 'varchar(100)', //@todo key
        'content' => 'text',
        'timestamp' => 'int',
        'edited_timestamp' => 'int',
        'pinned' => 'tinyint',
        'type' => 'int',
        // OBJECTS
        'referenced_message' => 'text',
        'message_reference' => 'text',
        'thread' => 'text',
        'author' => 'text',
        'poll' => 'text',
        // OBJECTS[]
        'attachments' => 'text',
        'embeds' => 'text',
        'reactions' => 'text',
        'sticker_items' => 'text',
        'mentions' => 'text',
        'mention_roles' => 'text',
        'mention_channels' => 'text',
    ];

    /**
     * Discord uses 'channel' for ANY type of message container (e.g. a thread) in addition to just 'channel'.
     * @param ?Migration $port
     * @see https://discord.com/developers/docs/topics/rate-limits#rate-limits
     */
    public function run(?Migration $port = null): void
    {
        // Discord-specific setup.
        $this->input->setHeader('Authorization', 'Bot ' . $this->config['token']);

        // Users
        $this->users();

        // Channels
        $this->textChannels();
        $channelIds = $this->getChannels(); // Before polluting with threads.
        $this->activeThreads();
        $this->archivedThreads($channelIds);

        // Messages
        $channelIds = $this->getChannels(); // Now including threads.
        $this->messages($channelIds);
    }

    /**
     * @return string
     */
    private function getGuildId(): string
    {
        return $this->config['extra']['guild_id'];
    }

    /**
     * @return array
     */
    private function getChannels(): array
    {
        // Start timer.
        $start = microtime(true);
        $rows = 0;
        Log::comment("Get pulled channel ids...");

        // Get discussion id list (avoiding empty discussions) from output.
        $channels = $this->outputQB()->from('discord_channels')->get('id')->toArray();
        $ids = array_column($channels, 'id');
        $memory = memory_get_usage();

        // Report.
        Log::storage('get', 'discord_channels', microtime(true) - $start, $rows, $memory);
        return $ids;
    }

    /**
     * @see https://discord.com/developers/docs/resources/guild#list-guild-members
     */
    protected function users(): void
    {
        $query = ['limit' => '1000'];
        $guildId = $this->getGuildId();
        $this->pull("guilds/$guildId/members", $query, null, self::DB_STRUCTURE_USERS, 'discord_users');
    }

    /**
     * 'Channel' can also refer to threads; here, we strictly mean OG text channels.
     * @see https://discord.com/developers/docs/resources/channel
     * @see https://discord.com/developers/docs/resources/guild#get-guild-channels
     */
    protected function textChannels(): void
    {
        $guildId = $this->getGuildId();
        $this->pull("guilds/$guildId/channels", [], null, self::DB_STRUCTURE_CHANNELS, 'discord_channels');
    }

    /**
     * Active threads PER GUILD (1).
     * @see https://discord.com/developers/docs/resources/guild#list-active-guild-threads
     */
    protected function activeThreads(): void
    {
        $guildId = $this->getGuildId();
        $this->pull("guilds/$guildId/threads/active", [], 'threads', self::DB_STRUCTURE_CHANNELS, 'discord_channels');
    }

    /**
     * Public archived threads PER CHANNEL.
     * @see https://discord.com/developers/docs/resources/channel#list-public-archived-threads
     */
    protected function archivedThreads(array $channelIds): void
    {
        foreach ($channelIds as $channelId) {
            $endpoint = "channels/$channelId/threads/archived/public";
            $this->pull($endpoint, [], 'threads', self::DB_STRUCTURE_CHANNELS, 'discord_channels');
            usleep(self::MIN_MICROSECONDS); // Kludged rate limit.
        }
    }

    /**
     * Default `limit` is 50; max is 100. Use `before` to page backwards.
     * @see https://discord.com/developers/docs/resources/message
     * @see https://discord.com/developers/docs/resources/message#message-object-message-types (types)
     */
    protected function messages(array $channelIds): void
    {
        foreach ($channelIds as $channelId) {
            $lastMessage = 0;
            //@todo do...while page as $lastMessage
            $query = ['before' => $lastMessage, 'limit' => '100'];
            $endpoint = "channels/$channelId/messages";
            $this->pull($endpoint, $query, null, self::DB_STRUCTURE_MESSAGES, 'discord_messages');
            usleep(self::MIN_MICROSECONDS); // Kludged rate limit.
        }
    }
}
