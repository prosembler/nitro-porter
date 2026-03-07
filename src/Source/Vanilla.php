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
    public const SUPPORTED = [
        'name' => 'Vanilla 2+',
        'defaultTablePrefix' => 'GDN_',
        'charsetTable' => 'Comment',
        'passwordHashMethod' => 'Vanilla',
        'avatarsPrefix' => 'p',
        'avatarThumbnailsPrefix' => 'n',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 'Cloud only',
            'Roles' => 1,
            'Avatars' => 1,
            'AvatarThumbnails' => 1,
            'PrivateMessages' => 1,
            'Signatures' => 1,
            'Attachments' => 1,
            'Bookmarks' => 1,
            'Badges' => 'Cloud or YAGA',
            'UserNotes' => 1,
            'Ranks' => 'Cloud or YAGA',
            'Groups' => 0, // @todo
            'Tags' => 1,
            'Reactions' => 'Cloud or YAGA',
        ]
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = array();

    /**
     */
    public function run(): void
    {
        // Core tables essentially map to our intermediate format as-is.
        $tables = [
            //'Activity',
            'Category',
            'Comment',
            'Conversation',
            'ConversationMessage',
            'Discussion',
            'Media',
            'Role',
            'Tag',
            'TagDiscussion',
            'UserComment',
            'UserConversation',
            'UserDiscussion',
            'UserMeta',
            'UserRole',
        ];
        foreach ($tables as $tableName) {
            if ($this->hasInputSchema($tableName)) {
                $this->export($tableName, "select * from :_{$tableName}");
            }
        }

        $this->users();
        $this->badges();
        $this->ranks();
        $this->reactions();
        $this->polls();
    }

    /**
     */
    public function users(): void
    {
        $map = [
            'Photo' => ['Column' => 'Photo', 'Type' => 'string', 'Filter' => 'vanillaPhoto'],
        ];
        $this->export('User', "select * from :_User u", $map);
    }

    /**
     * Badges support for cloud + Yaga.
     *
     */
    public function badges(): void
    {
        if ($this->hasInputSchema('Badge')) {
            // Vanilla Cloud
            $this->export('Badge', "select * from :_Badge");
            $this->export('UserBadge', "select * from :_UserBadge");
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
                Description as Body,
                Enabled as Visible
                from :_YagaBadge", $map);
            $this->export('UserBadge', "select *,
                DateInserted as DateCompleted
                from :_YagaBadgeAward");
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
            $this->export('Rank', "select * from :_Rank");
        } elseif ($this->hasInputSchema('YagaRank')) {
            // https://github.com/bleistivt/yaga
            $map = [
                'Description' => 'Body',
                'Sort' => 'Level',
                // Use 'Name' as both 'Name' and 'Label' (via SQL below)
            ];
            $this->export('Rank', "select *, Name as Label from :_YagaRank", $map);
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
            $this->export('ReactionType', "select * from :_ReactionType");
            //$ex->export('Reaction', "select * from :_Tag where Type='Reaction'");
            $this->export('UserTag', "select * from :_UserTag");
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
            $this->export('UserTag', "select * from :_YagaReaction", $map);
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
            $this->export('Poll', "select * from :_Poll");
            $this->export('PollOption', "select * from :_PollOption");
            $this->export('PollVote', "select * from :_PollVote");
        } elseif ($this->hasInputSchema('DiscussionPolls')) {
            // @todo https://github.com/hgtonight/Plugin-DiscussionPolls
            //$ex->export('Poll', "select * from :_DiscussionPollQuestions");
            //$ex->export('PollOption', "select * from :_DiscussionPollQuestionOptions");
            //$ex->export('PollVote', "select * from :_DiscussionPollAnswers");
        }
    }
}
