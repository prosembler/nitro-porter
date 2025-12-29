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

use Porter\Migration;
use Porter\Origin;

/**
 * A Discord server is referred to as a 'guild' in the API docs.
 * @see https://discord.com/developers/docs/reference
 */
class Discord extends Origin
{
    public const array SUPPORTED = [
        'name' => 'Discord',
    ];

    public const int MIN_MICROSECONDS = 20000; // Wait 20 milliseconds (1/50 sec) to force rate limit compliance.

    /**
     * All bots can make up to 50 requests per second to Discord's API.
     * 429 response code will have Retry-After header & retry_after in JSON body.
     * @param ?Migration $port
     * @see https://discord.com/developers/docs/topics/rate-limits#rate-limits
     */
    public function run(?Migration $port = null): void
    {
        // Discord-specific setup.
        $this->input->setHeader('Authorization', 'Bot ' . $this->config['token']);

        $this->users();
        $channelIds = $this->channels();
        $activeIds = $this->activeThreads();
        $archivedIds = $this->archivedThreads($channelIds);

        // Messages by channel / thread.
        $this->messages($channelIds);
        $this->messages($activeIds);
        $this->messages($archivedIds);
    }

    /**
     * @return string
     */
    private function getGuildId(): string
    {
        return $this->config['extra']['guild_id'];
    }

    /**
     * @see https://discord.com/developers/docs/resources/guild#list-guild-members
     */
    protected function users(): void
    {
        $fields = []; //@todo
        $request = ['limit' => '1000'];
        $guildId = $this->getGuildId();
        $this->pull("/guilds/$guildId/members", $request, $fields, 'discord_users');
    }

    /**
     * Discord uses 'channel' for ANY type of message container (e.g. a thread) in addition to just 'channel'.
     * Here, we stick to its in-app meaning.
     *
     * @see https://discord.com/developers/docs/resources/channel
     * @see https://discord.com/developers/docs/resources/guild#get-guild-channels
     */
    protected function channels(): array
    {
        $fields = [
            'id', 'type', 'guild_id', 'position', 'name', 'topic', 'last_message_id', 'parent_id', 'message_count'
        ];
        $guildId = $this->getGuildId();
        $this->pull("/guilds/$guildId/channels", [], $fields, 'discord_channels');
        return []; // @todo
    }

    /**
     * Active threads PER GUILD (1).
     * @see https://discord.com/developers/docs/resources/guild#list-active-guild-threads
     */
    protected function activeThreads(): array
    {
        $fields = [ // @todo update for threads
            'id', 'type', 'guild_id', 'position', 'name', 'topic', 'last_message_id', 'parent_id', 'message_count'
        ];
        $guildId = $this->getGuildId();
        $this->pull("/guilds/$guildId/threads/active", [], $fields, 'discord_threads');
        return []; // @todo
    }

    /**
     * Public archived threads PER CHANNEL.
     * @see https://discord.com/developers/docs/resources/channel#list-public-archived-threads
     */
    protected function archivedThreads(array $channelIds): array
    {
        $fields = [ // @todo update for threads
            'id', 'type', 'guild_id', 'position', 'name', 'topic', 'last_message_id', 'parent_id', 'message_count'
        ];
        foreach ($channelIds as $channelId) {
            $this->pull("/channels/$channelId/threads/archived/public", [], $fields, 'discord_threads');
        }
        return []; // @todo
    }

    /**
     * Default `limit` is 50; max is 100. Use `before` to page backwards.
     * @see https://discord.com/developers/docs/resources/message
     * @see https://discord.com/developers/docs/resources/message#message-object-message-types (types)
     */
    protected function messages(array $channelIds): void
    {
        $fields = [
            'id', 'channel_id', 'content', 'timestamp', 'edited_timestamp', 'pinned', 'type'
        ]; //@todo OBJECT: referenced_message, message_reference, thread, author, poll
        //@todo OBJECTS[]: attachments, embeds, reactions, sticker_items, mentions, mention_roles, mention_channels,

        foreach ($channelIds as $channelId) {
            $lastMessage = 0;
            //@todo do...while page as $lastMessage
            $request = ['before' => $lastMessage, 'limit' => '100'];
            $this->pull("/channels/$channelId/messages", $request, $fields, 'discord_messages');
        }
    }
}
