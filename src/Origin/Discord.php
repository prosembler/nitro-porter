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

use Porter\Config;
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

    /**
     * Discord has both a 50/sec rate limit (API) and a 10K/10min rate limit (Cloudflare).
     * 1 second = 1,000 milliseconds = 1,000,000 microseconds.
     * Limits are therefore 1/20000 microseconds (API) and 1/62500 microseconds (CF).
     * Because scrapes are likely to exceed 10 minutes, use the slower rate / longer wait.
     */
    public const int MIN_MICROSECONDS = 62500; // Wait to force rate limit compliance.

    public const int MICROSECONDS_PER_SEC = 1000000;

    public const int MAX_CHANNEL_QUERIES = 10000; // Channels + threads.

    public const int MAX_MESSAGE_QUERIES = 1000; // Per channel.

    public const array TEXT_CHANNEL_TYPES = [
        0 => 'GUILD_TEXT',
        5 => 'GUILD_ANNOUNCEMENT',
        11 => 'PUBLIC_THREAD',
        15 => 'GUILD_FORUM',
    ];

    /** @var array  */
    protected const array DB_STRUCTURE_USERS = [
        'nick' => 'varchar(100)',
        'avatar' => 'varchar(100)',
        'roles' => 'text',
        'joined_at' => 'varchar(100)',
        'premium_since' => 'varchar(100)',
        // Under 'user' object
        'id' => 'varchar(100)',
        'username' => 'varchar(100)',
        'discriminator' => 'varchar(100)',
        'global_name' => 'varchar(100)',
        'global_avatar' => 'varchar(100)',
        'email' => 'varchar(100)',
        'bot' => 'tinyint',
        'verified' => 'tinyint',
        'keys' => [
            'discord_users_id_primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ]
        ],
    ];

    /** @var array  */
    protected const array DB_STRUCTURE_CHANNELS = [
        'id' => 'varchar(100)',
        'type' => 'int', //@todo key?
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
        'keys' => [
            'discord_channels_id_primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ]
        ],
    ];

    /** @var array  */
    protected const array DB_STRUCTURE_MESSAGES = [
        'id' => 'varchar(100)',
        'channel_id' => 'varchar(100)', //@todo key?
        'content' => 'text',
        'timestamp' => 'varchar(100)', //@todo key?
        'edited_timestamp' => 'varchar(100)',
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
        'keys' => [
            'discord_messages_id_primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ]
        ],
    ];

    /**
     * Discord uses 'channel' for ANY type of message container (e.g. a thread) in addition to just 'channel'.
     * Current behavior is to REBUILD users & channels on every run, but RESUME messages from last ID.
     * @param ?Migration $port
     * @see https://discord.com/developers/docs/topics/rate-limits#rate-limits
     */
    public function run(?Migration $port = null): void
    {
        // Discord-specific setup.
        $this->input->setHeader('Authorization', 'Bot ' . $this->config['token']);

        // Users
        $this->users();

        // Roles

        // Channels
        $this->channels();
        $channelIds = $this->getTextChannels(); // Before polluting with threads.
        $this->activeThreads();
        $this->archivedThreads($channelIds);

        // Messages
        $channelIds = $this->getTextChannels(); // Now including threads.
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
     * @param array $headers
     * @param int $replySeconds
     * @see Https::get() for handling of 429s via `retry-after` header.
     */
    private function rateLimit(array $headers, int $replySeconds = 0): void
    {
        // Get header info.
        $limit = $headers['x-ratelimit-limit'][0] ?? null; // total number that can be made
        $remaining = $headers['x-ratelimit-remaining'][0] ?? null; // remaining number that can be made
        $reset = $headers['x-ratelimit-reset'][0] ?? null; // epoch time
        $after = $headers['x-ratelimit-reset-after'][0] ?? null; // seconds
        $bucket = $headers['x-ratelimit-bucket'][0] ?? null; //someID
        //$scope = $headers['x-ratelimit-scope'][0] ?? null; // user, shared, global

        // Log headers.
        //Log::comment("lim=$limit, rem=$remaining, res=$reset, aft=$after, bkt=$bucket");

        // Enforce rate limit with sleep.
        $wait = self::MIN_MICROSECONDS; // min pause to not reach CloudFlare limit.
        $wait = $wait - (int)($replySeconds * self::MICROSECONDS_PER_SEC); // Synchronous = subtract cycle time.
        if ($wait < 0) {
            $wait = 0;
        }
        if (!is_null($remaining) && empty($remaining)) { // Zero, not null
            if (!empty($after) && is_numeric($after)) {
                $wait = ceil($after) * self::MICROSECONDS_PER_SEC;
            } else {
                $wait = 60 * self::MICROSECONDS_PER_SEC; // 1 minute fallback.
                Log::comment("INFO: Failed to find rate limit info, but limit ($limit) exhausted; pausing 1 minute.");
            }
        }
        usleep($wait);
    }

    /**
     * Get channel id list.
     * @return array
     */
    private function getTextChannels(): array
    {
        // Allow config list to override.
        if (!empty($this->config['extra']['channels']) && count($this->config['extra']['channels'])) {
            return $this->config['extra']['channels'];
        }

        // Get channels from database.
        $channels = $this->outputQB()->from('discord_channels')
            ->distinct()->limit(10000) // Safety from repeat pulls & invalid data.
            ->whereIn('type', array_keys(self::TEXT_CHANNEL_TYPES))
            ->get('id')->toArray();
        return array_column($channels, 'id');
    }

    /**
     * Get oldest message imported.
     * @param int $channelID
     * @return int
     */
    private function getLastMessageId(int $channelID): int
    {
        $message = $this->outputQB()->from('discord_messages')
            ->orderBy('timestamp', 'asc')->limit(1)
            ->where('channel_id', $channelID)
            ->get('id')->first();
        return $message->id ?? 0;
    }

    /**
     * @see https://discord.com/developers/docs/resources/guild#list-guild-members
     */
    protected function users(): void
    {
        $query = ['limit' => '1000'];
        $guildId = $this->getGuildId();
        $map = [
            'user' => [ // obj.param => flatName
                'id' => 'id',
                'username' => 'username',
                'discriminator' => 'discriminator',
                'global_name' => 'global_name',
                'avatar' => 'global_avatar',
                'email' => 'email',
                'bot' => 'bot',
                'verified' => 'verified',
            ],
        ];
        $this->pull("guilds/$guildId/members", self::DB_STRUCTURE_USERS, 'discord_users', $query, null, $map);
    }

    /**
     * 'Channel' can also refer to threads; here, it does not.
     * @see https://discord.com/developers/docs/resources/channel
     * @see https://discord.com/developers/docs/resources/guild#get-guild-channels
     */
    protected function channels(): void
    {
        $guildId = $this->getGuildId();
        $this->pull("guilds/$guildId/channels", self::DB_STRUCTURE_CHANNELS, 'discord_channels');
    }

    /**
     * Active threads PER GUILD (1).
     * @see https://discord.com/developers/docs/resources/guild#list-active-guild-threads
     */
    protected function activeThreads(): void
    {
        $guildId = $this->getGuildId();
        $this->pull("guilds/$guildId/threads/active", self::DB_STRUCTURE_CHANNELS, 'discord_channels', [], 'threads');
    }

    /**
     * Public archived threads PER CHANNEL.
     * @see https://discord.com/developers/docs/resources/channel#list-public-archived-threads
     */
    protected function archivedThreads(array $channelIds): void
    {
        foreach ($channelIds as $channelId) {
            $endpoint = "channels/$channelId/threads/archived/public";
            $info = $this->pull($endpoint, self::DB_STRUCTURE_CHANNELS, 'discord_channels', [], 'threads');
            $this->rateLimit($info['headers']);
        }
    }

    /**
     * Cycle through channels 1 call each to avoid getting rate limited.
     *
     * Discord/Cloudflare seems to interpret as identical GET despite $query changing.
     * It gives a 5-sec timeout every ~10 "identical" requests, which slows things down badly.
     * This means you're effectively limited to ~5K messages per minute if you don't cycle channels.
     */
    protected function messages(array $channelIds): void
    {
        if (count($channelIds) > self::MAX_CHANNEL_QUERIES) {
            Log::comment("INFO: Found more than MAX_CHANNEL_QUERIES.");
            return;
        }

        // Status tracking.
        $channels = array_fill_keys($channelIds, false);
        $k = 0;

        // Process until channels' status=true.
        do {
            $k++;
            $channels = $this->messageLoop($channels);
            $remaining = count(array_diff($channels, [true]));

            // Report.
            $warn = (1 === $remaining) ? " Expect 5-second timeouts." : '';
            $now = date('H:i:s e');
            Log::comment("\nINFO: $remaining channels incomplete after pass $k at $now.$warn\n");
        } while (0 !== $remaining);
    }

    /**
     * Do 1 cycle through all channels to get a batch of messages.
     * Endpoint default `limit` is 50; max is 100. Use `before` to page backwards.
     *  > Returns an array of message objects from newest to oldest on success.
     * @see https://discord.com/developers/docs/resources/message
     * @see https://discord.com/developers/docs/resources/message#message-object-message-types (types)
     * @param array $channels
     * @return array
     */
    protected function messageLoop(array $channels): array
    {
        foreach ($channels as $channelId => $status) {
            // Check status.
            if (true === $status) {
                continue; // Channel is done, bail.
            } elseif (false === $status) { // Resume?
                $channels[$channelId] = $this->getLastMessageId($channelId);
                Log::comment("\nResumed channel $channelId at message {$channels[$channelId]}");
            }

            // Setup & pull batch.
            $endpoint = "channels/$channelId/messages";
            $query = ['limit' => '100'];
            if (is_numeric($channels[$channelId]) && $channels[$channelId]) {
                $query['before'] = $channels[$channelId];
            }
            $info = $this->pull($endpoint, self::DB_STRUCTURE_MESSAGES, 'discord_messages', $query);

            // Update status.
            if (0 === (int)$info['rows']) {
                // Change status to 'done' if no more rows found.
                $channels[$channelId] = true;
                Log::comment("Channel $channelId has no messages past {$channels[$channelId]}, skipping.");
            } elseif (isset($info['last']['id'])) {
                // Update offset & report where we are.
                $id = $channels[$channelId] = $info['last']['id']; // Should be oldest message.
                $elapsed = formatElapsed($info['api_time']);
                $time = $info['last']['timestamp'] ?? '';
                Log::comment("> $elapsed; last message id=$id; timestamp=" . $time);
            }

            // Rate limit.
            $this->rateLimit($info['headers'], $info['pull_time']);
        }

        return $channels;
    }
}
