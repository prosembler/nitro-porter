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

    public const string CDN_BASE_URI = 'https://cdn.discordapp.com/';

    /**
     * Discord has both a 50/sec rate limit (API) and a 10K/10min rate limit (Cloudflare).
     * 1 second = 1,000 milliseconds = 1,000,000 microseconds.
     * Limits are therefore 1/20000 microseconds (API) and 1/62500 microseconds (CF).
     * Because scrapes are likely to exceed 10 minutes, use the slower rate / longer wait.
     */
    public const int MIN_MICROSECONDS = 62500; // Wait to force rate limit compliance.

    public const int MICROSECONDS_PER_SEC = 1000000;

    public const int MAX_CHANNEL_QUERIES = 10000; // Channels + threads.

    /**
     * API max is 100, but (if pulling reactions) it is more optimal to make it far smaller.
     *
     * Discord batches all /channel/$x in the same rate limit bucket.
     * Looping through channels (<5 queries each) sidesteps it, but inline reactions querying makes this a challenge.
     * Queueing reactions in memory is the solution, but if memory management becomes an issue, lower this.
     */
    public const int MAX_MESSAGES = 100;

    public const int MAX_FILE_BATCH = 25;

    public const int MAX_FILE_ERRORS = 10;

    public const array TEXT_CHANNEL_TYPES = [
        0 => 'GUILD_TEXT',
        5 => 'GUILD_ANNOUNCEMENT',
        11 => 'PUBLIC_THREAD',
        15 => 'GUILD_FORUM',
    ];

    protected const array SCHEMA_USERS = [
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
    ];

    protected const array SCHEMA_REACTIONS = [
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
    ];

    protected const array SCHEMA_USER_REACTIONS = [
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
    ];

    protected const array SCHEMA_ROLES = [
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
    ];

    protected const array SCHEMA_EMOJIS = [
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
    ];

    protected const array SCHEMA_POLLS = [
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
    ];

    protected const array SCHEMA_POLL_ANSWERS = [
        'new_id' => 'increments',
        'poll_id' => 'bigint',
        'answer_id' => 'bigint',
        'count' => 'int',
        'emoji_id' => 'bigint',
        'text' => 'text',
        'keys' => [
            'discord_answers_index' => [
                'type' => 'unique',
                'columns' => ['answer_id'],
            ]
        ],
    ];

    protected const array SCHEMA_POLL_USER_ANSWERS = [
        'poll_id' => 'bigint',
        'answer_id' => 'bigint',
        'user_id' => 'bigint',
        'keys' => [
            'discord_user_answers_index' => [
                'type' => 'unique',
                'columns' => ['poll_id', 'answer_id', 'user_id'],
            ]
        ],
    ];

    protected const array SCHEMA_CHANNELS = [
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
    ];

    /**
     * @var array Name => column type
     */
    protected const array SCHEMA_MESSAGES = [
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
    ];

    protected const array SCHEMA_USERROLES = [
        'user_id' => 'bigint',
        'role_id' => 'bigint',
    ];

    protected const array SCHEMA_ATTACHMENTS = [
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
    ];

    protected const array MAP_USERS = [
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

    /** @var array IDs of guild users used to find non-guild users. */
    protected array $guildUsers;

    /** @var array IDs of guild emojis used to find non-guild emojis. */
    protected array $guildEmojis;

    /**
     * Discord uses 'channel' for ANY type of message container (e.g. a thread) in addition to just 'channel'.
     * Current behavior is to REBUILD users & channels on every run, but RESUME messages from last ID.
     * @see https://discord.com/developers/docs/topics/rate-limits#rate-limits
     */
    public function run(): void
    {
        // Discord-specific setup.
        $this->originStorage->setHeader('Authorization', 'Bot ' . $this->config['token']);
        $this->attachmentFolder = $this->getDownloadFolder('attachments');

        // Guild users & roles
        $this->users(); // @todo Timing issue: setting $guildUsers for messages()
        $this->roles();

        // Guild taxonomy & emoji
        $this->emojis();
        $this->channels();
        $this->threads();

        // Guild content + non-guild users/emoji.
        $this->messages();

        // Download last to catch late additions.
        $this->downloadAvatars();
        $this->downloadEmojis();
    }

    private function getGuildId(): string
    {
        return $this->config['extra']['guild_id'];
    }

    /**
     * Discord uses custom rate limit headers.
     *
     * Min pause to not reach CloudFlare limit = MIN_MICROSECONDS
     * @see Origin::respect429s() for handling of 429s via `retry-after` header.
     *
     * x-ratelimit-limit - total number that can be made
     * x-ratelimit-remaining - remaining number that can be made
     * x-ratelimit-reset - epoch time when limit resets
     * x-ratelimit-reset-after - seconds til reset
     * x-ratelimit-bucket - what limit bucket this limit is in
     * x-ratelimit-scope - user, shared, or global
     */
    protected function rateLimit(array $headers, float $elapsed = 0): int
    {
        // Get header info.
        $limit = $headers['x-ratelimit-limit'][0] ?? null;
        $remaining = $headers['x-ratelimit-remaining'][0] ?? null;
        $after = $headers['x-ratelimit-reset-after'][0] ?? null;

        // Calculate min pause for syncronous requests by subtracting cycle time from minimum.
        $wait = self::MIN_MICROSECONDS - (int)($elapsed * self::MICROSECONDS_PER_SEC);
        $wait = max(min($wait, self::MIN_MICROSECONDS), 0); // 0 <= $wait <= self::MIN_MICROSECONDS

        // Extend the wait if headers dictate.
        if (!is_null($remaining) && empty($remaining)) { // Zero (not null)
            $msg = "> Header rate limit ($limit) exhausted: ";
            if (!empty($after) && is_numeric($after)) {
                $wait = ceil($after) * self::MICROSECONDS_PER_SEC;
                Log::comment($msg . "pausing " . round($after, 1) . "s (per `reset-after`)");
            } else {
                $wait = 60 * self::MICROSECONDS_PER_SEC; // 1 minute fallback.
                Log::comment($msg . "pausing 1m (no `reset-after` found)");
            }
        }

        return $wait;
    }

    /**
     * Get channel id list.
     */
    private function getTextChannels(array $types): array
    {
        // Allow config list to override.
        if (!empty($this->config['extra']['channels']) && count($this->config['extra']['channels'])) {
            return $this->config['extra']['channels'];
        }

        // Get channels from database.
        $channels = $this->outputQB()->from('discord_channels')
            ->distinct()->limit(10000) // Safety from repeat pulls & invalid data.
            ->whereIn('type', array_keys($types))
            ->get('id')->toArray();
        return array_column($channels, 'id');
    }

    /**
     * Get oldest message imported.
     */
    private function getLastMessageId(int $channelID): int
    {
        $message = $this->outputQB()->from('discord_messages')
            ->orderBy('timestamp', 'asc')->limit(1)
            ->where('channel_id', $channelID)
            ->get('id')->first();
        return $message->id ?? 0;
    }

    /** @see https://discord.com/developers/docs/resources/guild#list-guild-members */
    protected function users(): void
    {
        $query = ['limit' => '1000']; // @todo Loop to find remaining users.
        $endpoint = "guilds/" . $this->getGuildId() . "/members";
        $info = $this->pull($endpoint, self::SCHEMA_USERS, 'discord_users', null, $query, self::MAP_USERS, []);
        $this->guildUsers = array_column($info['content'], 'id');
        $this->extractUserRoles($info['content']);
    }

    /** @see https://discord.com/developers/docs/topics/permissions#role-object */
    protected function roles(): void
    {
        $endpoint = "guilds/" . $this->getGuildId();
        $this->pull($endpoint, self::SCHEMA_ROLES, 'discord_roles', 'roles', [], [], []);
    }

    /**
     * Generate intermediary table to unpack user role associations before 'normal' export.
     */
    protected function extractUserRoles(array $users): void
    {
        $users = array_column($users, 'roles', 'id');
        $userRoles = [];
        foreach ($users as $id => $roles) {
            foreach ($roles as $roleID) {
                $userRoles[] = ['user_id' => $id, 'role_id' => $roleID];
            }
        }
        $this->extract('discord_user_roles', self::SCHEMA_USERROLES, $userRoles);
    }

    /**
     * Generate intermediary table to unpack attachments from their messages & download them.
     *
     * @param array $messages Message records from Discord API. Attachments are a LIST per message.
     */
    protected function extractAttachments(array $messages): void
    {
        $data = []; // Store to database.
        $files = []; // Smaller list for asyncDownloads().

        // Allow HttpClient to get trashed to sidestep memory leak from errors.
        static $errors = 0;
        if ($errors >= self::MAX_FILE_ERRORS) {
            $this->originStorage->resetConnection('discord'); // @todo Use originName.
            Log::comment("\nINFO: HttpClient reset after [$errors] file download errors.\n");
            $errors = 0; // Reset counter.
            gc_collect_cycles(); // Force garbage collection.
        }

        foreach ($messages as $message) {
            if (empty($message['attachments'])) {
                continue;
            }
            $year = date('Y', strtotime($message['timestamp']));
            foreach ($message['attachments'] as $attachment) {
                $attachment['message_id'] = $message['id'];
                if ($attachment['download_path'] = $this->getDownloadPath($attachment, $year)) {
                    $files[$attachment['url']] = $attachment['download_path'];
                }
                $data[$attachment['url']] = $attachment;

                // Batch downloads.
                if (count($files) >= self::MAX_FILE_BATCH) {
                    $errors += $this->originStorage->asyncDownload($files);
                    $files = []; // reset
                }
            }
        }
        $errors += $this->originStorage->asyncDownload($files); // Final batch.
        $this->extract('discord_attachments', self::SCHEMA_ATTACHMENTS, $data);
    }

    /**
     * Use attachment data to generate a file path for downloading it.
     */
    protected function getDownloadPath(array $attachment, string $year): string
    {
        $filename = $this->limitFilenameLength($attachment['filename']);
        $folder = $this->getDownloadFolder('attachments/' . $year);
        $path = $folder . $attachment['id'] . '_' . $filename;
        return (file_exists($path)) ? '' : $path; // Don't request downloading duplicates.
    }

    /**
     * Porter currently lacks a way to migrate an emoji set but we still pull them for backup purposes.
     * @see https://discord.com/developers/docs/resources/emoji#emoji-object
     */
    protected function emojis(): void
    {
        $endpoint = "guilds/" . $this->getGuildId() . "/emojis";
        $info = $this->pull($endpoint, self::SCHEMA_EMOJIS, 'discord_emojis', null, [], [], []);
        $this->guildEmojis = array_column($info['content'], 'id');
    }

    /**
     * Discord supplies all versions regardless of original.
     *
     * Only downloads gif version if animated=1, otherwise png/webp.
     * @see https://discord.com/developers/docs/reference#image-formatting
     * > **** For Custom Emoji, we highly recommend requesting emojis as WebP for maximum performance and compatibility.
     *   >> Emojis can be uploaded as JPEG, PNG, GIF, WebP, and AVIF formats.
     *   >> WebP and AVIF formats must be requested as WebP since they don't convert well to other formats.
     */
    protected function downloadEmojis(): void
    {
        if ($folder = $this->getDownloadFolder('emojis')) {
            $emojis = $this->outputQB()->from('discord_emojis')->get(['id', 'name', 'animated'])->toArray();
            $downloadCount = 0;
            Log::comment("Found " . count($emojis) . " emojis.");
            foreach ($emojis as $emoji) {
                $url = self::CDN_BASE_URI . 'emojis/' . $emoji->id . '.';
                $types = ['webp', 'png', 'gif']; // 'jpg', 'jpeg' also valid
                foreach ($types as $type) {
                    $path = $folder . $emoji->name . '.' . $type;
                    $stillNonGif = ('gif' !== $type && 0 === $emoji->animated);
                    $animatedGif = ('gif' === $type && 1 === $emoji->animated);
                    if (!file_exists($path) && ($animatedGif || $stillNonGif)) {
                        $this->originStorage->download($url . $type, $path);
                        $downloadCount++;
                        echo "."; // Dotted line to show progress.
                    }
                }
            }
            Log::comment("Downloaded $downloadCount emoji variants.");
        }
    }

    /** @see https://discord.com/developers/docs/reference#image-formatting-cdn-endpoints */
    protected function downloadAvatars(): void
    {
        if ($folder = $this->getDownloadFolder('avatars')) {
            $users = $this->outputQB()->from('discord_users')->get(['id', 'avatar', 'global_avatar'])->toArray();
            $downloadCount = 0;
            Log::comment("Finding avatars for " . count($users) . " users.");
            foreach ($users as $user) {
                $url = $this->getAvatarUrl($user);
                $path = $folder . 'avatar_' . $user->id . '.png';
                if ($url && !file_exists($path)) {
                    $this->originStorage->download($url, $path);
                    $downloadCount++;
                    echo "."; // Dotted line to show progress.
                }
            }
            Log::comment("Downloaded $downloadCount avatars.");
        }
    }

    /**
     * 'Channel' can also refer to threads; here, it does not.
     * @see https://discord.com/developers/docs/resources/channel
     * @see https://discord.com/developers/docs/resources/guild#get-guild-channels
     */
    protected function channels(): void
    {
        $endpoint = "guilds/" . $this->getGuildId() . "/channels";
        $this->pull($endpoint, self::SCHEMA_CHANNELS, 'discord_channels', null, [], [], []);
    }

    /**
     * Active threads per GUILD (1) & public archived threads per CHANNEL.
     * @see https://discord.com/developers/docs/resources/guild#list-active-guild-threads
     * @see https://discord.com/developers/docs/resources/channel#list-public-archived-threads
     */
    protected function threads(): void
    {
        // Active threads.
        $endpoint = "guilds/" . $this->getGuildId() . "/threads/active";
        $this->pull($endpoint, self::SCHEMA_CHANNELS, 'discord_channels', 'threads', [], [], []);

        // Archived threads.
        $channelIds = $this->getTextChannels(array_diff(self::TEXT_CHANNEL_TYPES, ['PUBLIC_THREAD'])); // No threads.
        foreach ($channelIds as $channelId) {
            $endpoint = "channels/$channelId/threads/archived/public";
            $info = $this->pull($endpoint, self::SCHEMA_CHANNELS, 'discord_channels', 'threads', [], [], []);
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
    protected function messages(): void
    {
        $channelIds = $this->getTextChannels(self::TEXT_CHANNEL_TYPES);
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
            if (empty($warn) || ($k % 10 === 0)) { // Only output every 10 runs after down to 1 channel.
                Log::comment("\nINFO: $remaining channels incomplete after pass $k at $now.$warn\n");
            }
        } while (0 !== $remaining);
    }

    /**
     * Do 1 cycle through all channels to get a batch of messages.
     * Endpoint default `limit` is 50; max is 100. Use `before` to page backwards.
     *  > Returns an array of message objects from newest to oldest on success.
     * @see https://discord.com/developers/docs/resources/message
     * @see https://discord.com/developers/docs/resources/message#message-object-message-types (types)
     *
     * Attachments should be collected now, while we're sure the timeouts are still fresh
     *   and we are tracking iterative backups so we can grab only new ones.
     * @see https://discord.com/developers/docs/reference#signed-attachment-cdn-urls
     * @see https://docs.discord.com/developers/resources/message#attachment-object
     * @example https://cdn.discordapp.com/attachments/1012345678900020080/1234567891233211234/my_image.png
     *       ?ex=65d903de - Hex timestamp indicating when an attachment CDN URL will expire
     *       &is=65c68ede - Hex timestamp indicating when the URL was issued
     *       &hm=2481f30dd67f503f54d020ae3b5533b9987fae4e55f2b4e3926e08a3fa3ee24f& - Signature
     */
    protected function messageLoop(array $channels): array
    {
        $hasMessagesTable = $this->outputStorage->exists('discord_messages');
        $userReactionsQueue = [];
        $finished = [];
        foreach ($channels as $channelId => $status) {
            // Check status.
            if (true === $status) {
                continue; // Channel is done, bail.
            } elseif (false === $status && $hasMessagesTable) { // Resume?
                $start = microtime(true);
                $channels[$channelId] = $this->getLastMessageId($channelId);
                $elapsed = microtime(true) - $start;
                $append = ($elapsed > 0.5) ? " (lookup was " . round($elapsed, 2) . "s)" : '';
                if (0 !== $channels[$channelId]) {
                    Log::comment("\nResuming channel $channelId @ msg_id={$channels[$channelId]}$append:");
                }
            }

            // Setup & pull batch.
            $endpoint = "channels/$channelId/messages";
            $query = ['limit' => self::MAX_MESSAGES];
            $map = [
                'author' => ['id' => 'authorid'],
            ];
            if (is_numeric($channels[$channelId]) && $channels[$channelId]) {
                $query['before'] = $channels[$channelId];
            }
            $info = $this->pull($endpoint, self::SCHEMA_MESSAGES, 'discord_messages', null, $query, $map, []);
            if (empty($info)) {
                continue; // @todo $info isn't safe as an array
            }

            // Attachments.
            $this->extractAttachments($info['content']);

            // Reactions & non-guild emoji used in them.
            $userReactionsQueue[$channelId] = $this->extractReactions($info['content'], $channelId);

            // Non-guild authors.
            $this->extractAuthors($info['content']);

            // Polls.
            $this->extractPolls($info['content'], $channelId);

            // Note completed channels.
            if (0 === (int)$info['rows']) {
                // Change status to 'done' if no more rows found.
                $finished[$channelId] = true;
                //Log::comment("> channel $channelId has no messages past {$channels[$channelId]}, skipping.");
            }

            if (isset($info['last']['id'])) {
                // Update offset & report where we are.
                $id = $channels[$channelId] = $info['last']['id']; // Should be oldest message.
                $time = $info['last']['timestamp'] ?? '';
                Log::comment("> last_msg=$id @ " . $time);
            }

            // Rate limit.
            $this->rateLimit($info['headers'], $info['pull_time']);
        }

        $this->processUserReactions(array_filter($userReactionsQueue));

        // Do not record 'finished' until AFTER all reactions are processed.
        // Sooner likely to cause data loss. It's safe to redo; NOT safe to lose queue.
        foreach ($finished as $channelId => $status) {
            $channels[$channelId] = $status; // array_merge() would renumber keys.
        }

        return $channels;
    }

    /**
     * Get & store data for non-guild users to fill in gaps.
     *
     * Authors are stored as a SINGLE user object on messages.
     */
    protected function extractAuthors(array $content): void
    {
        $messages = array_column($content, 'author', 'id');
        $users = [];
        foreach ($messages as $author) {
            $users[$author['id']] = $author;
        }

        // Find missing IDs.
        $missingUserIDs = array_diff(array_keys($users), $this->guildUsers);
        if (empty($missingUserIDs)) {
            return;
        }

        // Insert missing users.
        $missingUsers = array_intersect_key($users, $missingUserIDs);
        $info = $this->extract('discord_users', self::SCHEMA_USERS, $missingUsers);

        // Log actions & mark users as "found".
        if (!empty($info['rows'])) { // Missing users were inserted.
            Log::comment("> non-guid user(s) added: " . implode(',', $missingUsers));
            if ($info['rows'] !== count($missingUsers)) { // Some missing users weren't inserted.
                $countSkipped = count($missingUsers) - $info['rows'];
                Log::comment("> WARNING: $countSkipped user(s) were not captured");
            }
            $this->guildUsers = array_merge($this->guildUsers, $missingUserIDs);
        }
    }

    /**
     * From a list of messages, extract & store all reactions & their emoji.
     *
     * In discord_reactions, store msg_id + emoji_id + count.
     * In discord_user_reactions, store msg_id + emoji_id + user_id.
     * In discord_emoji, store all emoji we haven't seen yet.
     *
     * Reactions are stored as a LIST of objects on messages, each with emoji + count.
     * @see https://docs.discord.com/developers/resources/message#reaction-object
     * @see https://discord.com/developers/docs/resources/emoji#emoji-object
     * ex: `[{"emoji":{"id":"742118343112130694","name":"gritty"},"count":1},
     *       {"emoji":{"id":"976301342576504912","name":"rockon"},"count":9},
     *       {"emoji":{"id":null,"name": "🔥"},"count":3},
     * }]`
     *
     * Individual users' reactions must then be requested per message, per reaction; stored as LIST of users.
     * @see https://docs.discord.com/developers/resources/message#get-reactions
     * ex:  `/channels/{channel.id}/messages/{message.id}/reactions/{emoji.id}`
     */
    protected function extractReactions(array $content, int $channelId): array
    {
        // Filter to messages with reactions and discard other message data.
        $msgsWithReactions = array_filter(
            array_column($content, 'reactions', 'id'),
            fn($reactions) => (!empty($reactions))
        );
        if (empty($msgsWithReactions)) {
            return [];
        }

        // Build lists of non-standard emoji to extract & reactions to store.
        $emojiList = [];
        $reactList = [];
        $userReactionQueue = [];

        // Process all messages with reactions.
        foreach ($msgsWithReactions as $msgId => $msgReactions) {
            foreach ($msgReactions as $reaction) {
                $emojiId = $reaction['emoji']['id'];

                // Collect non-standard emoji for fetching.
                if (!empty($emojiId)) {
                    // Collect for fetching.
                    $emojiList[$emojiId] = $reaction['emoji'];
                    // Special format for GET. "To use custom emoji, you must encode it in the format name:id"
                    $urlEmojiId = $reaction['emoji']['name'] . ':' . $emojiId;
                } else {
                    // Standard emoji just go by their unicode.
                    $urlEmojiId = $reaction['emoji']['name'];
                }

                // Edge case: HttpClient produces '#' (not '%23') for emoji "hash key" (\u{0023}\u{20E3}).
                $urlEmojiId = rawurlencode($urlEmojiId);
                //$urlEmojiId = str_replace("\u{0023}\u{20E3}", '%23%EF%B8%8F%E2%83%A3', $urlEmojiId);

                // Build reaction list w/ counts for storing.
                $reactList[] = [
                    'emoji_id' => $emojiId ?? 0, // Std unicode emoji ID = null.
                    'emoji_name' => $reaction['emoji']['name'] ?? '',
                    'count' => $reaction['count'] ?? 0,
                    'message_id' => $msgId,
                ];

                // Avoid an associative array to conserve memory, but enforce position.
                $userReactionQueue[] = [
                    'msg' => $msgId,
                    'url' => $urlEmojiId,
                    'id' => $emojiId,
                    'name' => $reaction['emoji']['name'] ?? ''
                ];
            }
        }

        // Store collected lists.
        $this->extractEmoji($emojiList);
        $this->extract('discord_reactions', self::SCHEMA_REACTIONS, $reactList);

        Log::comment("> " . count($userReactionQueue) . " reactions queued");
        return $userReactionQueue;
    }

    /**
     * Empty the queue of per-user reactions to pull.
     *
     * It might make sense to bail when there are < 5 channels in the queue to prevent timeouts,
     * but that's an extra level of things to track at the messageLoop level that probably isn't worth it.
     * A bigger timeout problem awaits at the end if 1 channel stacks far more than the rest anyway.
     */
    protected function processUserReactions(array $queue): void
    {
        $pass = 1;
        do {
            $remaining = 0;
            foreach ($queue as $channelId => $reactions) {
                // Process next reaction in the queue.
                $reaction = array_pop($reactions);
                $info = $this->pull(
                    endpoint: "/channels/$channelId/messages/{$reaction['msg']}/reactions/{$reaction['url']}",
                    fields: self::SCHEMA_USER_REACTIONS,
                    tableName: 'discord_user_reactions',
                    map: ['id' => 'user_id'],
                    storeAll: [ // Added feature for this use case.
                        'message_id' => $reaction['msg'],
                        'emoji_id' => $reaction['id'],
                        'emoji_name' => $reaction['name']
                    ]
                );
                // Update the queue.
                if (empty($reactions)) {
                    unset($queue[$channelId]); // Done here.
                } else {
                    $queue[$channelId] = $reactions; // -1 item.
                    $remaining += count($reactions);
                }
                // Rate limit.
                $this->rateLimit($info['headers'], $info['pull_time']);
            }
            $channels = count($queue);
            Log::comment("> $remaining reactions remaining in queue across $channels channels after pass $pass");
            $pass++;
        } while (count($queue));
    }


    /**
     * Get & store data for non-standard, non-guild emojis to fill in gaps.
     */
    protected function extractEmoji(array $emojis): void
    {
        $missingEmojiIDs = array_diff(array_keys($emojis), $this->guildEmojis);
        if (empty($missingEmojiIDs)) {
            return; // No missing emojis found.
        }
        $this->guildEmojis = array_merge($this->guildEmojis, $missingEmojiIDs); // Update in-memory list.
        $emojiData = array_diff_key($emojis, array_combine($this->guildEmojis, $this->guildEmojis));
        $this->extract('discord_emojis', self::SCHEMA_EMOJIS, $emojiData); // Store new emoji.
        Log::comment("> non-guild emoji(s) added: " . implode(',', $missingEmojiIDs));
    }

    /**
     * From a list of messages, extract & store all polls.
     *
     * Polls are stored as an object on messages.
     * @see https://docs.discord.com/developers/resources/poll
     *
     * Answers are stored as a list of objects.
     * @see https://docs.discord.com/developers/resources/poll#poll-answer-object-poll-answer-object-structure
     *
     * Answer voters are a LIST of user objects per answer.
     * @see https://docs.discord.com/developers/resources/poll#get-answer-voters
     * ex: /channels/{channel.id}/polls/{message.id}/answers/{answer_id}
     *
     * Ignores edge case where a custom emoji used in a poll option was never used as a reaction.
     */
    protected function extractPolls(array $content, int $channelId): void
    {
        // Filter to messages with polls and discard other message data.
        $msgsWithPolls = array_filter(
            array_column($content, 'poll', 'id'),
            fn($poll) => (!empty($poll))
        );
        if (empty($msgsWithPolls)) {
            return;
        }

        // Build lists of all polls & answers.
        $pollData = [];
        $answerData = [];

        // Process all messages with reactions.
        foreach ($msgsWithPolls as $msgId => $poll) {
            // Build list of all polls in these messages.
            $pollData[] = [
                'id' => $msgId, // 1:1 poll:msg associations, so this is both message_id & poll_id.
                'question' => $poll['question']['text'] ?? null,
                'allow_multiselect' => $poll['allow_multiselect'],
                'expiry' => $poll['expiry'],
                'emoji' => $poll['question']['emoji']['id'] ?? null,
                'is_final' => (!empty($poll['results'])) ? $poll['results']['is_finalized'] : 0,
            ];

            // Build list of all answers in these messages w/ counts for storage.
            $counts = null;
            if (!empty($poll['results'])) {
                // Index answer counts by id so they can be referenced in the loop.
                $counts = array_column($poll['results']['answer_counts'], 'count', 'id');
            }
            foreach ($poll['answers'] as $answer) {
                $answerData[] = [
                    'poll_id' => $msgId, // message_id === poll_id
                    'answer_id' => $answer['answer_id'],
                    'text' => $answer['poll_media']['text'] ?? null,
                    'emoji_id' => $answer['poll_media']['emoji']['id'] ?? null,
                    'count' => ($counts) ? $counts[$answer['answer_id']] : 0,
                ];

                // Pull user-answers. We only need `user_id` from the API.
                $this->pull(
                    endpoint: "/channels/$channelId/polls/$msgId/answers/" . $answer['answer_id'],
                    fields: self::SCHEMA_POLL_USER_ANSWERS,
                    tableName: 'discord_poll_user_answers',
                    key: 'users',
                    map: ['id' => 'user_id'],
                    storeAll: ['poll_id' => $msgId, 'answer_id' => $answer['answer_id']]
                );
            }
        }

        // Store collected lists.
        $this->extract('discord_polls', self::SCHEMA_POLLS, $pollData);
        $this->extract('discord_poll_answers', self::SCHEMA_POLL_ANSWERS, $answerData);
    }

    /**
     * Get URL for a user's Discord avatar.
     */
    protected function getAvatarUrl(object $user): string
    {
        if (!$user->global_avatar && !$user->avatar) {
            return '';
        }

        $url = 'avatars/' . $user->id . '/' . $user->global_avatar;
        if ($user->avatar) {
            $url = 'guilds/' . $this->getGuildId() . '/users/' . $user->id . '/avatars/' . $user->avatar;
        }
        return self::CDN_BASE_URI . $url . '.png';
    }
}
