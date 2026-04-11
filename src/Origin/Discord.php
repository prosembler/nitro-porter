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

use Illuminate\Database\Query\Builder;
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

    public const array TEXT_CHANNEL_TYPES = [
        0 => 'GUILD_TEXT',
        5 => 'GUILD_ANNOUNCEMENT',
        11 => 'PUBLIC_THREAD',
        15 => 'GUILD_FORUM',
    ];

    protected const array DB_USERS = [
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

    protected const array DB_ROLES = [
        'id' => 'varchar(100)',
        'name' => 'varchar(100)',
        'position' => 'int',
        'managed' => 'tinyint',
        'mentionable' => 'tinyint',
    ];

    protected const array DB_EMOJIS = [
        'id' => 'varchar(100)',
        'name' => 'varchar(100)',
        'user' => 'text', // author
        'animated' => 'tinyint',
    ];

    protected const array DB_CHANNELS = [
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

    /**
     * @var array Name => column type
     * @see \Porter\Source::renumber() for why an index is important.
     */
    protected const array DB_MESSAGES = [
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
        'authorid' => 'text', // Derived from author.id — flattened to allow Source to filter.
        // OBJECTS[]
        'poll' => 'text', // @see https://discord.com/developers/docs/resources/poll#poll-object
        'attachments' => 'text', // @see https://discord.com/developers/docs/resources/message#attachment-object
        'embeds' => 'text', // @see https://discord.com/developers/docs/resources/message#embed-object
        'reactions' => 'text', // @see https://discord.com/developers/docs/resources/message#reaction-object
        'sticker_items' => 'text',
        'mentions' => 'text',
        'mention_roles' => 'text',
        'mention_channels' => 'text',
        'keys' => [ //  Index any keys that may require renumbering (for auto-joins).
            'discord_messages_id_primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ]
        ],
    ];

    protected const array DB_USERROLES = [
        'user_id' => 'bigint',
        'role_id' => 'bigint',
    ];

    protected const array DB_ATTACHMENTS = [
        'id' => 'text',
        'message_id' => 'bigint', // renumber?
        'filename' => 'text',
        'url' => 'text',
        'size' => 'bigint',
        'width' => 'int',
        'height' => 'int',
        'content_type' => 'varchar(100)',
        'download_path' => 'text', // where we put the file; not in Discord's response
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

    /** @var string Folder path to download attachment files into. */
    protected string $attachmentFolder;

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
        $this->users();
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

    /** @see Https::get() for handling of 429s via `retry-after` header. */
    private function rateLimit(array $headers, float $replySeconds = 0): void
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
        $info = $this->pull($endpoint, self::DB_USERS, 'discord_users', null, $query, self::MAP_USERS);
        $this->guildUsers = array_column($info['content'], 'id');
        $this->extractUserRoles($info['content']);
    }

    /** @see https://discord.com/developers/docs/topics/permissions#role-object */
    protected function roles(): void
    {
        $endpoint = "guilds/" . $this->getGuildId();
        $this->pull($endpoint, self::DB_ROLES, 'discord_roles', 'roles');
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
        $this->extract('discord_user_roles', self::DB_USERROLES, $userRoles, true);
    }

    /**
     * Generate intermediary table to unpack attachments from their messages & download them.
     *
     * @param array $messages Message records from Discord API. Attachments are a LIST per message.
     */
    protected function extractAttachments(array $messages): void
    {
        // Collapse messages to a list of downloads.
        $downloads = [];
        $files = [];
        $folder = $this->getDownloadFolder('attachments');
        $messages = array_column($messages, 'attachments', 'id');
        foreach ($messages as $message_id => $attachments) {
            foreach ($attachments as $attachment) {
                $attachment['message_id'] = $message_id;
                $filename = $this->limitFilenameLength($attachment['filename']);
                $attachment['download_path'] = $folder . $attachment['id'] . '_' . $filename;
                $downloads[$attachment['url']] = $attachment;
                // Smaller array for asyncDownloads();
                $files[$attachment['url']] = $attachment['download_path'];
            }
        }
        unset($messages); // This could be quite large, and we're about to fire many requests.

        if (!empty($downloads)) {
            // Save records.
            $this->extract('discord_attachments', self::DB_ATTACHMENTS, $downloads, true);
            unset($downloads);

            // Retrieve files.
            if ($folder) {
                $this->originStorage->asyncDownload($files);
            }
        }
    }

    /**
     * Porter currently lacks a way to migrate an emoji set but we still pull them for backup purposes.
     * @see https://discord.com/developers/docs/resources/emoji#emoji-object
     */
    protected function emojis(): void
    {
        $endpoint = "guilds/" . $this->getGuildId() . "/emojis";
        $info = $this->pull($endpoint, self::DB_EMOJIS, 'discord_emojis');
        $this->guildEmojis = array_column($info['content'], 'id');
    }

    /**
     * @see https://discord.com/developers/docs/reference#image-formatting
     * > **** For Custom Emoji, we highly recommend requesting emojis as WebP for maximum performance and compatibility.
     *   >> Emojis can be uploaded as JPEG, PNG, GIF, WebP, and AVIF formats.
     *   >> WebP and AVIF formats must be requested as WebP since they don't convert well to other formats.
     */
    protected function downloadEmojis(): void
    {
        if ($folder = $this->getDownloadFolder('emojis')) {
            $emojis = $this->outputQB()->from('discord_emojis')->get(['id', 'name'])->toArray();
            foreach ($emojis as $emoji) {
                $url = self::CDN_BASE_URI . 'emojis/' . $emoji->id . '.';
                $types = ['webp', 'png', 'jpg', 'gif', 'jpeg']; // By probability-ish.
                // Originals may be PNG, JPEG, WebP, or GIF. Only WebP is guaranteed to exist.
                $found = 0;
                foreach ($types as $type) {
                    $path = $folder . $emoji->name . '.' . $type;
                    if (!file_exists($path)) {
                        $this->originStorage->download($url . $type, $path);
                    }
                    if (2 === $found++) {
                        break; // There aren't more than two variants (non-webp original + webp).
                    }
                }
            }
        }
    }

    /** @see https://discord.com/developers/docs/reference#image-formatting-cdn-endpoints */
    protected function downloadAvatars(): void
    {
        if ($folder = $this->getDownloadFolder('avatars')) {
            $ids = $this->outputQB()->from('discord_users')->get('id')->toArray();
            foreach ($ids as $id) {
                $url = self::CDN_BASE_URI . 'avatars/' . $id . '/user_avatar.png';
                $path = $folder . 'avatar_' . $id . '.png';
                if (!file_exists($path)) {
                    $this->originStorage->download($url, $path);
                }
            }
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
        $this->pull($endpoint, self::DB_CHANNELS, 'discord_channels');
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
        $this->pull($endpoint, self::DB_CHANNELS, 'discord_channels', 'threads');

        // Archived threads.
        $channelIds = $this->getTextChannels(array_diff(self::TEXT_CHANNEL_TYPES, ['PUBLIC_THREAD'])); // No threads.
        foreach ($channelIds as $channelId) {
            $endpoint = "channels/$channelId/threads/archived/public";
            $info = $this->pull($endpoint, self::DB_CHANNELS, 'discord_channels', 'threads');
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
            Log::comment("\nINFO: $remaining channels incomplete after pass $k at $now.$warn\n");
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
        $this->outputStorage->prepare('discord_attachments', self::DB_ATTACHMENTS);
        foreach ($channels as $channelId => $status) {
            // Check status.
            if (true === $status) {
                continue; // Channel is done, bail.
            } elseif (false === $status && $hasMessagesTable) { // Resume?
                $channels[$channelId] = $this->getLastMessageId($channelId);
                if (0 !== $channels[$channelId]) {
                    Log::comment("Resuming channel $channelId at message {$channels[$channelId]}:");
                }
            }

            // Setup & pull batch.
            $endpoint = "channels/$channelId/messages";
            $query = ['limit' => '100'];
            $map = [
                'author' => ['id' => 'authorid'],
            ];
            if (is_numeric($channels[$channelId]) && $channels[$channelId]) {
                $query['before'] = $channels[$channelId];
            }
            $info = $this->pull($endpoint, self::DB_MESSAGES, 'discord_messages', null, $query, $map);

            // Attachments.
            $this->extractAttachments($info['content']);

            // Non-guild emoji.
            $this->extractRemedialEmoji($info['content']);

            // Non-guild authors.
            $this->extractRemedialUsers($info['content']);

            // Update status.
            if (0 === (int)$info['rows']) {
                // Change status to 'done' if no more rows found.
                $channels[$channelId] = true;
                //Log::comment("> channel $channelId has no messages past {$channels[$channelId]}, skipping.");
            } elseif (isset($info['last']['id'])) {
                // Update offset & report where we are.
                $id = $channels[$channelId] = $info['last']['id']; // Should be oldest message.
                $time = $info['last']['timestamp'] ?? '';
                Log::comment("> last_msg=$id @ " . $time);
            }

            // Rate limit.
            $this->rateLimit($info['headers'], $info['pull_time']);
        }

        return $channels;
    }

    /**
     * Get & store data for non-guild users to fill in gaps.
     *
     * Authors are stored as a SINGLE user object on messages.
     */
    protected function extractRemedialUsers(array $content): void
    {
        $users = array_column($content, 'author', 'id');
        $missingUsers = array_diff_key($users, array_combine($this->guildUsers, $this->guildUsers));
        $this->extract('discord_users', self::DB_USERS, $missingUsers);
    }

    /**
     * Get & store data for non-guild emojis to fill in gaps.
     *
     * Reactions are stored as a LIST of emoji objects on messages.
     * @see https://discord.com/developers/docs/resources/emoji#emoji-object
     * ex: `[{"emoji":{"id":"742118343112130694","name":"gritty"},"count":1},
     *       {"emoji":{"id":"976301342576504912","name":"rockon"},"count":9}]`
     */
    protected function extractRemedialEmoji(array $content): void
    {
        $messages = array_column($content, 'reactions');
        $msgsWithEmojis = array_filter($messages, fn ($reactions) => (!empty($reactions)));
        foreach ($msgsWithEmojis as $rows) {
            $emojis = array_column(array_column($rows, 'emoji'), 'id', 'id');
            $emojis = array_filter($emojis, fn ($emoji) => !empty($emoji['id']));
            if (empty($emojis)) {
                continue;
            }
            $missingEmojis = array_diff($emojis, array_combine($this->guildEmojis, $this->guildEmojis));
            $this->extract('discord_emojis', self::DB_EMOJIS, $missingEmojis);
        }
    }

    /**
     * Retrieve the file.
     */
    protected function getFile(string $url, string $filename): void
    {
        if (!$this->attachmentFolder) {
            return;
        }
        $path = $this->attachmentFolder . $filename;
        if (!file_exists($path)) {
            $this->originStorage->download($url, $path);
        } else {
            Log::comment("Notice: Attachment '{$filename}' already exists.");
        }
    }

    /**
     * Change a filename so that the basename is no more than $length characters.
     *
     * Prevents error "Failed to open stream: File name too long".
     */
    protected function limitFilenameLength(string $filename, int $length = 100): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return substr(pathinfo($filename, PATHINFO_FILENAME), 0, $length) . '.' . $ext;
    }
}
