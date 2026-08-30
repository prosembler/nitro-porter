<?php

/**
 * Discord exporter tool
 *
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;

/**
 * @see \Porter\Origin\Discord
 */
class Discord extends Source
{
    public const array INFO = [
        'name' => 'Discord',
        'defaultTablePrefix' => '',
        'charsetTable' => 'discord_messages',
    ];

    /** @var int Milliseconds from Unix Epoch. */
    public const int DISCORD_EPOCH_DIFF = 1288834974657;

    public const array CHANNEL_TYPE = [
        'GUILD_TEXT' => 0,
        'GUILD_CATEGORY' => 4,
        'GUILD_ANNOUNCEMENT' => 5,
        'PUBLIC_THREAD' => 11,
        'GUILD_FORUM' => 15,
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
        'fileTransferSupport' => true,
        'renumberIndices' => true,
    ];

    protected function users(): void
    {
        $map = [
            'new_id' => 'UserID',
            'derived_name' => 'Name', // prefer 1) nick 2) global_name 3) username
            'derived_avatar' => 'Photo', // prefer guild-specific 'avatar' to 'global_avatar'
            'joined_at' => 'DateInserted', // Guild-specific date
        ];
        $query = $this->sourceQB()->from('discord_users')->select('discord_users.*')
            ->selectRaw('COALESCE(nick, COALESCE(global_name, username)) as derived_name')
            ->selectRaw('COALESCE(avatar, global_avatar) as derived_avatar');
        $this->export('User', $query, $map);
    }

    protected function roles(): void
    {
        $map = [
            'new_id' => 'RoleID',
            'name' => 'Name',
            //position, managed, mentionable
        ];
        $query = $this->sourceQB()->from('discord_roles')->distinct('id')->select();
        $this->export('Role', $query, $map);

        // UserRoles
        $map = [
            'user_id' => 'UserID', // Already renumbered.
            'new_role_id' => 'RoleID',
        ];
        $query = $this->sourceQB()->from('discord_user_roles')
            ->join('discord_roles', 'discord_roles.id', '=', 'discord_user_roles.role_id')
            ->select(['discord_user_roles.*', 'discord_roles.new_id as new_role_id']);
        $this->export('UserRole', $query, $map);
    }

    protected function categories(): void
    {
        $map = [
            'new_id' => 'CategoryID',
            'name' => 'Name',
            'new_parent_id' => 'ParentCategoryID',
            'position' => 'Sort',
            'topic' => 'Description',
            //'new_last_message_id' => 'LastCommentID',
        ];
        $query = $this->sourceQB()->from('discord_channels')
            ->select(['discord_channels.*', 'dc.new_id as new_parent_id'])
            ->join('discord_channels as dc', 'discord_channels.parent_id', '=', 'dc.id')
            ->whereIn('discord_channels.type', [
                self::CHANNEL_TYPE['GUILD_CATEGORY'],
                self::CHANNEL_TYPE['GUILD_FORUM'],
                self::CHANNEL_TYPE['GUILD_TEXT']
            ]);
        $this->export('Category', $query, $map);
    }

    protected function discussions(): void
    {
        $map = [
            'new_id' => 'DiscussionID',
            'name' => 'Name',
            'new_parent_id' => 'CategoryID',
            'new_owner_id' => 'InsertUserID',
            //'last_message_id' => 'LastCommentID', // Cannot be updated due to timing.
            'derived_timestamp' => 'DateInserted',
        ];
        $filters = [
            'new_parent_id' => fn($val, $col, $row) // Text channels use 'id' as 'parent_id' — they are own category.
                => (Discord::CHANNEL_TYPE['GUILD_TEXT'] === $row['type']) ? $row['new_id'] : $row['new_parent_id'],
            'derived_timestamp' => __NAMESPACE__ . '\Discord::timestampFromSnowflake',
        ];
        $query = $this->sourceQB()->from('discord_channels')
            ->join('discord_users', 'discord_users.id', '=', 'discord_channels.owner_id')
            ->join('discord_channels as dcparent', 'discord_channels.parent_id', '=', 'dcparent.id')
            ->select(['discord_channels.*',
                'discord_users.new_id as new_owner_id',
                'discord_channels.id as derived_timestamp',
                'dcparent.new_id as new_parent_id'])
            ->whereIn('discord_channels.type', [
                self::CHANNEL_TYPE['PUBLIC_THREAD'],
                self::CHANNEL_TYPE['GUILD_ANNOUNCEMENT'],
                self::CHANNEL_TYPE['GUILD_TEXT']
            ]);
        $this->export('Discussion', $query, $map, $filters);
    }

    protected function comments(): void
    {
        $map = [
            'new_id' => 'CommentID',
            'content' => 'Body',
            'new_channel_id' => 'DiscussionID',
            'new_authorid' => 'InsertUserID',
            'pinned' => 'Announce',
            //'embeds' => '',
                // [{"type":"link","url":"http:\/\/www.example.com","description":"Your source for video game news..."}]
        ];
        $query = $this->sourceQB()->from('discord_messages')
            ->join('discord_channels', 'discord_channels.id', '=', 'discord_messages.channel_id')
            ->join('discord_users', 'discord_users.id', '=', 'discord_messages.authorid')
            ->select(['discord_messages.*',
                'discord_channels.new_id as new_channel_id',
                'discord_users.new_id as new_authorid'])
            ->selectRaw('timestamp(timestamp) as DateInserted')
            ->selectRaw('timestamp(edited_timestamp) as DateUpdated');
        $this->export('Comment', $query, $map);
    }

    protected function attachments(): void
    {
        $map = [
            'new_id' => 'MediaID',
            'new_message_id' => 'ForeignID', // Always a message_id
            'filename' => 'Name',
            'width' => 'ImageWidth',
            'height' => 'ImageHeight',
            'size' => 'Size',
            'content_type' => 'Type',
            'download_path' => 'SourceFullPath',
        ];
        $query = $this->sourceQB()->from('discord_attachments')
            ->join('discord_messages', 'discord_messages.id', '=', 'discord_attachments.message_id')
            ->select(['discord_attachments.*', 'discord_messages.new_id as new_message_id']);
        $this->export('Media', $query, $map);
    }

    protected function emojis(): void
    {
        $map = [
            'new_id' => 'EmojiID',
            'name' => 'Name',
            'animated' => 'Animated',
            'user.id' => 'InsertUserID',
        ];
        $query = $this->sourceQB()->from('discord_emojis')->select('discord_emojis.*');
        $this->export('Emoji', $query, $map);
    }

    protected function reactions(): void
    {
        // Custom emoji reactions.
        // Tag: Emoji => Reactions => Tags are all the same thing for our purposes.
        $map = [
            'new_id' => 'TagID',
            'name' => 'Name',
        ];
        $query = $this->sourceQB()->from('discord_emojis')
            ->select('discord_emojis.*')
            ->selectRaw('"reaction" as Type');
        $this->export('Tag', $query, $map);

        // ReactionType: All Tags we just added are Reactions.
        // Unbuffered use of PorterQB -> must put in memory!
        $data = $this->porterQB()->from('Tag')->get(['Name', 'TagID'])->toArray();
        $this->porterStorage->prepare('ReactionType', $this->porterStructure['ReactionType']);
        $info = $this->porterStorage->store('ReactionType', [], $this->porterStructure['ReactionType'], $data, []);
        Log::storage('export', $info); // Manually log the 'export'.

        // UserTag: Individual user reactions.
        $map = [
            'new_user_id' => 'UserID',
            'new_message_id' => 'RecordID',
            'new_emoji_id' => 'TagID',
        ];
        $query = $this->sourceQB()->from('discord_user_reactions')
            ->join('discord_users', 'discord_users.id', '=', 'discord_user_reactions.user_id')
            ->join('discord_messages', 'discord_messages.id', '=', 'discord_user_reactions.message_id')
            ->join('discord_emojis', 'discord_emojis.id', '=', 'discord_user_reactions.emoji_id')
            ->select(['discord_users.new_id as new_user_id',
                'discord_messages.new_id as new_message_id',
                'discord_emojis.new_id as new_emoji_id']);
        $this->export('UserTag', $query, $map);

        // UserTag: Reaction counts.
        $map = [
            'new_emoji_id' => 'TagID',
            'new_message_id' => 'RecordID',
            'count' => 'Total',
        ];
        $query = $this->sourceQB()->from('discord_reactions')
            ->join('discord_emojis', 'discord_emojis.id', '=', 'discord_reactions.emoji_id')
            ->join('discord_messages', 'discord_messages.id', '=', 'discord_reactions.message_id')
            ->select(['discord_reactions.*',
                'discord_emojis.new_id as new_emoji_id',
                'discord_messages.new_id as new_message_id'])
            ->selectRaw('"Comment-Total" as RecordType');
        $this->export('UserTag', $query, $map);
    }

    protected function polls(): void
    {
        // Polls.
        $map = [
            'new_id' => 'PollID',
            'question' => 'Name',
            'allow_multiselect' => 'AllowMultiple',
            'expiry' => 'DateClosed',
            'timestamp' => 'DateInserted',
            'edited_timestamp' => 'DateUpdated',
        ];
        $query = $this->sourceQB()->from('discord_polls')
            ->join('discord_messages', 'discord_messages.id', '=', 'discord_polls.id')
            ->join('discord_channels', 'discord_channels.id', '=', 'discord_messages.channel_id')
            ->join('discord_users', 'discord_users.id', '=', 'discord_messages.authorid')
            ->select(['discord_polls.expiry', 'discord_polls.allow_multiselect', 'discord_polls.question',
                'discord_polls.new_id', 'discord_messages.timestamp', 'discord_messages.edited_timestamp',
                'discord_messages.new_id as CommentID',
                'discord_channels.new_id as DiscussionID',
                'discord_users.new_id as InsertUserID']);
        $this->export('Poll', $query, $map);

        // Answers.
        $map = [
            'new_id' => 'PollOptionID',
            'text' => 'Body',
            'count' => 'CountVotes',
        ];
        $query = $this->sourceQB()->from('discord_poll_answers')
            ->join('discord_polls', 'discord_polls.id', '=', 'discord_poll_answers.poll_id')
            ->join('discord_emojis', 'discord_emojis.id', '=', 'discord_poll_answers.emoji_id')
            ->select(['discord_poll_answers.text', 'discord_poll_answers.count', 'discord_poll_answers.new_id',
                'discord_polls.new_id as PollID',
                'discord_emojis.new_id as EmojiID']);
        $this->export('PollOption', $query, $map);

        // Votes.
        $map = [
            'new_user_id' => 'UserID',
            'new_poll_id' => 'PollID',
            'new_answer_id' => 'PollOptionID',  // answer_is non-unique in Discord
        ];
        $query = $this->sourceQB()->from('discord_poll_user_answers as ua')
            ->join('discord_users', 'discord_users.id', '=', 'ua.user_id')
            ->join('discord_polls', 'discord_polls.id', '=', 'ua.poll_id')
            ->join('discord_poll_answers', function ($join) {
                $join->on('discord_poll_answers.poll_id', '=', 'ua.poll_id')
                    ->where('discord_poll_answers.answer_id', '=', 'ua.answer_id');
            })
            ->select(['discord_polls.new_id as new_poll_id',
                'discord_users.new_id as new_user_id',
                'discord_poll_answers.new_id as new_answer_id']);
        $this->export('PollVote', $query, $map);
    }

    /**
     * Discord SnowflakeIDs have timestamps embedded within them.
     *
     * @param mixed $value A Discord SnowflakeID
     * @return ?string MySQL timestamp
     */
    public static function timestampFromSnowflake(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $timestamp = (($value >> 22) + self::DISCORD_EPOCH_DIFF) / 1000;
        return gmdate("Y-m-d H:i:s", (int)$timestamp); // FROM_UNIXTIME() equivalent.
    }
}
