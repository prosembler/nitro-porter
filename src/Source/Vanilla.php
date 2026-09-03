<?php

/**
 * Vanilla 2+ exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

class Vanilla extends Source
{
    public const array INFO = [
        'name' => 'Vanilla 2+',
        'defaultTablePrefix' => 'GDN_',
        'charsetTable' => 'Comment',
        'passwordHashMethod' => 'Vanilla',
        'avatarsPrefix' => 'p',
        'avatarThumbnailsPrefix' => 'n',
    ];

    public const array FEATURE_REQUIREMENTS = [
        'Badges' => ['enabled' => 'Cloud or YAGA'],
        'Ranks' => ['enabled' => 'Cloud or YAGA'],
        'Reactions' => ['enabled' => 'Cloud or YAGA'],
    ];

    public array $sourceTables = [];

    public function categories(): void
    {
        if ($this->hasInputSchema('Category')) {
            $this->export('Category', $this->sourceQB()->select()->from('Category'));
        }
    }

    public function comments(): void
    {
        if ($this->hasInputSchema('Comment')) {
            $this->export('Comment', $this->sourceQB()->select()->from('Comment'));
        }
    }

    public function conversations(): void
    {
        if ($this->hasInputSchema('Conversation')) {
            $this->export('Conversation', $this->sourceQB()->select()->from('Conversation'));
            $this->export('ConversationMessage', $this->sourceQB()->select()->from('ConversationMessage'));
            //UserConversation
        }
    }

    public function discussions(): void
    {
        if ($this->hasInputSchema('Discussion')) {
            $this->export('Discussion', $this->sourceQB()->select()->from('Discussion'));
        }
    }

    public function attachments(): void
    {
        if ($this->hasInputSchema('Media')) {
            $this->export('Media', $this->sourceQB()->select()->from('Media'));
        }
    }

    public function roles(): void
    {
        if ($this->hasInputSchema('Role')) {
            $this->export('Role', $this->sourceQB()->select()->from('Role'));
            $this->export('UserRole', $this->sourceQB()->select()->from('UserRole'));
        }
    }

    public function tags(): void
    {
        if ($this->hasInputSchema('Tag')) {
            $this->export('Tag', $this->sourceQB()->select()->from('Tag'));
            $this->export('TagDiscussion', $this->sourceQB()->select()->from('TagDiscussion'));
        }
    }

    public function wallposts(): void
    {
        if ($this->hasInputSchema('UserComment')) {
            $this->export('UserComment', $this->sourceQB()->select()->from('UserComment'));
        }
    }

    public function bookmarks(): void
    {
        if ($this->hasInputSchema('UserDiscussion')) {
            $this->export('UserDiscussion', $this->sourceQB()->select()->from('UserDiscussion'));
        }
    }

    public function users(): void
    {
        $filters = [
            'Photo' => 'VanillaPhoto',
        ];
        $this->export('User', $this->sourceQB()->select()->from('User'), [], $filters);
        $this->export('UserMeta', $this->sourceQB()->select()->from('UserMeta'));
    }

    /**
     * Badges support for cloud + Yaga.
     *
     */
    public function badges(): void
    {
        if ($this->hasInputSchema('Badge')) {
            // Vanilla Cloud
            $this->export('Badge', $this->sourceQB()->select()->from('Badge'));
            $this->export('UserBadge', $this->sourceQB()->select()->from('UserBadge'));
        } elseif ($this->hasInputSchema('YagaBadge')) {
            // https://github.com/bleistivt/yaga
            $map = [
                'Description' => 'Body',
                'RuleClass' => 'Type',
                'RuleCriteria' => 'Attributes', // This probably doesn't actually work, but we'll try.
                'AwardValue' => 'Points',
                'Enabled' => 'Active',
            ];
            // Yaga is missing a couple columns we need.
            $this->export('Badge', "select *,
                NOW() as DateInserted,
                1 as InsertUserID,
                Enabled as Visible
                from :_YagaBadge", $map);
            $this->export('UserBadge', "select *, DateInserted as DateCompleted from :_YagaBadgeAward");
        }
    }

    /**
     * Ranks support for cloud + Yaga.
     *
     */
    public function ranks(): void
    {
        if ($this->hasInputSchema('Rank')) {
            // Vanilla Cloud
            $this->export('Rank', $this->sourceQB()->select()->from('Rank'));
        } elseif ($this->hasInputSchema('YagaRank')) {
            // https://github.com/bleistivt/yaga
            $map = [
                'Description' => 'Body',
                'Sort' => 'Level',
                // Use 'Name' as both 'Name' and 'Label' (via SQL below)
            ];
            $this->export('Rank', $this->sourceQB()->select(['*', 'Name as Label'])->from('YagaRank'), $map);
        }
    }

    /**
     * Reactions support for cloud + Yaga.
     *
     */
    public function reactions(): void
    {
        if ($this->hasInputSchema('ReactionType')) {
            // Vanilla Cloud & later open source
            $this->export('ReactionType', $this->sourceQB()->select()->from('ReactionType'));
            //$ex->export('Reaction', "select * from :_Tag where Type='Reaction'");
            $this->export('UserTag', $this->sourceQB()->select()->from('UserTag'));
        } elseif ($this->hasInputSchema('YagaReaction')) {
            // https://github.com/bleistivt/yaga
            // Shortcut use of Tag table by setting ActionID = TagID.
            // This wouldn't work for exporting a Yaga-based Vanilla install to a "standard" reactions Vanilla install,
            // but I have to assume no one is using Porter for that anyway.
            // Other Targets should probably directly join ReactionType & UserTag on TagID anyway.
            // Yaga also lacks an 'active/enabled' field so assume they're all 'on'.
            $this->export('ReactionType', "select *,
                ActionID as TagID,
                1 as Active
                from :_YagaAction"); // Name & Description only
            $map = [
                'ParentID' => 'RecordID',
                'ParentType' => 'RecordType',
                'InsertUserID' => 'UserID',
                'ParentScore' => 'Total',
                'ActionID' => 'TagID',
            ];
            $this->export('UserTag', $this->sourceQB()->select()->from('YagaReaction'), $map);
        }
    }

    /**
     * Polls support for cloud + "DiscussionPolls".
     *
     */
    public function polls(): void
    {
        if ($this->hasInputSchema('Poll')) {
            // SaaS
            $this->export('Poll', $this->sourceQB()->select()->from('Poll'));
            $this->export('PollOption', $this->sourceQB()->select()->from('PollOption'));
            $this->export('PollVote', $this->sourceQB()->select()->from('PollVote'));
        } elseif ($this->hasInputSchema('DiscussionPolls')) {
            // @todo https://github.com/hgtonight/Plugin-DiscussionPolls
            //$ex->export('Poll', "select * from :_DiscussionPollQuestions");
            //$ex->export('PollOption', "select * from :_DiscussionPollQuestionOptions");
            //$ex->export('PollVote', "select * from :_DiscussionPollAnswers");
        }
    }
}
