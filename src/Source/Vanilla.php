<?php

/**
 * Vanilla 2+ exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

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
     * @param Migration $port
     */
    public function run(Migration $port): void
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
            if ($port->hasInputSchema($tableName)) {
                $port->export($tableName, "select * from :_{$tableName}");
            }
        }

        $this->users($port);
        $this->badges($port);
        $this->ranks($port);
        $this->reactions($port);
        $this->polls($port);
    }

    /**
     * @param Migration $port
     */
    public function users(Migration $port): void
    {
        $map = [
            'Photo' => ['Column' => 'Photo', 'Type' => 'string', 'Filter' => 'vanillaPhoto'],
        ];
        $port->export('User', "select * from :_User u", $map);
    }

    /**
     * Badges support for cloud + Yaga.
     *
     * @param Migration $port
     */
    public function badges(Migration $port): void
    {
        if ($port->hasInputSchema('Badge')) {
            // Vanilla Cloud
            $port->export('Badge', "select * from :_Badge");
            $port->export('UserBadge', "select * from :_UserBadge");
        } elseif ($port->hasInputSchema('YagaBadge')) {
            // https://github.com/bleistivt/yaga
            $map = [
                'Description' => 'Body',
                'RuleClass' => 'Type',
                'RuleCriteria' => 'Attributes', // This probably doesn't actually work, but we'll try.
                'AwardValue' => 'Points',
                'Enabled' => 'Active',
            ];
            // Yaga is missing a couple columns we need.
            $port->export('Badge', "select *,
                NOW() as DateInserted,
                1 as InsertUserID,
                Description as Body,
                Enabled as Visible
                from :_YagaBadge", $map);
            $port->export('UserBadge', "select *,
                DateInserted as DateCompleted
                from :_YagaBadgeAward");
        }
    }

    /**
     * Ranks support for cloud + Yaga.
     *
     * @param Migration $port
     */
    public function ranks(Migration $port): void
    {
        if ($port->hasInputSchema('Rank')) {
            // Vanilla Cloud
            $port->export('Rank', "select * from :_Rank");
        } elseif ($port->hasInputSchema('YagaRank')) {
            // https://github.com/bleistivt/yaga
            $map = [
                'Description' => 'Body',
                'Sort' => 'Level',
                // Use 'Name' as both 'Name' and 'Label' (via SQL below)
            ];
            $port->export('Rank', "select *, Name as Label from :_YagaRank", $map);
        }
    }

    /**
     * Reactions support for cloud + Yaga.
     *
     * @param Migration $port
     */
    public function reactions(Migration $port): void
    {
        if ($port->hasInputSchema('ReactionType')) {
            // Vanilla Cloud & later open source
            $port->export('ReactionType', "select * from :_ReactionType");
            //$ex->export('Reaction', "select * from :_Tag where Type='Reaction'");
            $port->export('UserTag', "select * from :_UserTag");
        } elseif ($port->hasInputSchema('YagaReaction')) {
            // https://github.com/bleistivt/yaga
            // Shortcut use of Tag table by setting ActionID = TagID.
            // This wouldn't work for exporting a Yaga-based Vanilla install to a "standard" reactions Vanilla install,
            // but I have to assume no one is using Porter for that anyway.
            // Other Targets should probably directly join ReactionType & UserTag on TagID anyway.
            // Yaga also lacks an 'active/enabled' field so assume they're all 'on'.
            $port->export('ReactionType', "select *,
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
            $port->export('UserTag', "select * from :_YagaReaction", $map);
        }
    }

    /**
     * Polls support for cloud + "DiscussionPolls".
     *
     * @param Migration $port
     */
    public function polls(Migration $port): void
    {
        if ($port->hasInputSchema('Poll')) {
            // SaaS
            $port->export('Poll', "select * from :_Poll");
            $port->export('PollOption', "select * from :_PollOption");
            $port->export('PollVote', "select * from :_PollVote");
        } elseif ($port->hasInputSchema('DiscussionPolls')) {
            // @todo https://github.com/hgtonight/Plugin-DiscussionPolls
            //$ex->export('Poll', "select * from :_DiscussionPollQuestions");
            //$ex->export('PollOption', "select * from :_DiscussionPollQuestionOptions");
            //$ex->export('PollVote', "select * from :_DiscussionPollAnswers");
        }
    }
}
