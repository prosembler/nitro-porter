<?php

/**
 *
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Target;

use Porter\Log;
use Porter\Formatter;
use Porter\Target;

/**
 * You'll notice a seemingly random mix of datetime and timestamp in the Flarum database.
 *
 * Synch0, 2022-08-01:
 * > Back in 2014-16, the default was datetime, but then Laravel switched to timestamp by default.
 */
class Flarum extends Target
{
    public const array SUPPORTED = [
        'name' => 'Flarum',
        'defaultTablePrefix' => 'FLA_',
        'avatarPath' => 'assets/avatars',
        'attachmentPath' => 'assets/files/imported',
        'features' => [
            'Users' => 1,
            'Roles' => 1,
            'Avatars' => 1,
            'Categories' => 'tags',
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 'fof/polls',
            'PrivateMessages' => 'fof/byobu',
            'Attachments' => 'fof/uploads',
            'Bookmarks' => 'subscriptions',
            'Badges' => 'v17development/flarum-user-badges',
            'Reactions' => 'fof/reactions',
        ]
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
        'fileTransferSupport' => true,
    ];

    /** @var int Offset for inserting OP content into the posts table. */
    protected int $discussionPostOffset = 0;

    /** @var int Offset for inserting PMs into posts table. */
    protected int $messagePostOffset = 0;

    /** @var int  Offset for inserting PMs into discussions table. */
    protected int $messageDiscussionOffset = 0;

    /**
     * Check for issues that will break the import.
     */
    public function validate(): void
    {
        // Flarum must have unique usernames. Report users skipped (because of `insert ignore`).
        // Unsure fix could be automated. Manually edit existing forum data for now to rectify dupe issues.
        // Would need to find data attached & possibly merge. Would need IDs etc from findDuplicates().
        $dupes = array_diff($this->findDuplicates('User', 'Name'), Formatter::DELETED_USERNAMES);
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.name: ' . implode(', ', $dupes));
        }

        // Flarum must have unique emails. Report users skipped (because of `insert ignore`).
        $dupes = $this->findDuplicates('User', 'Email');
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.email: ' . implode(', ', $dupes));
        }
    }

    protected function setup(): void
    {
        // Ignore constraints on tables that block import.
        $this->ignoreOutputDuplicates('users');
    }

    protected function cleanup(): void
    {
        // Empty access tokens for a fresh forum.
        if ($this->dbOutput()->getSchemaBuilder()->hasTable('access_tokens')) {
            $this->dbOutput()->table('access_tokens')->truncate();
        }
    }

    protected function precontent(): void
    {
        // Singleton factory; depends on Users being done.
        Formatter::instance()->buildUserMap($this);
    }

    /**
     * Duplicated logic between discussions() and privateMessages() for Flarum plugin reasons.
     */
    protected function getDiscussionSchema(): array
    {
        $structure = $this->getSchema('discussions');

        // fof/gamification — no data, just prevent failure (no default values are set)
        if ($this->hasOutputSchema('discussions', ['votes'])) {
            $structure['votes'] = 'int';
            $structure['hotness'] = 'double';
        }

        // flarumite/simple-discussion-views
        if ($this->hasOutputSchema('discussions', ['view_count'])) {
            $structure['view_count'] = 'int';
        }

        // fof/best-answer
        if ($this->hasOutputSchema('discussions', ['best_answer_notified'])) {
            $structure['best_answer_notified'] = 'tinyint';
        }

        return $structure;
    }

    protected function users(): void
    {
        $map = [
            'UserID' => 'id',
            'Name' => 'username',
            'Email' => 'email',
            'Password' => 'password',
            'Photo' => 'avatar_url',
            'DateInserted' => 'joined_at',
            'DateLastActive' => 'last_seen_at',
            'CountDiscussions' => 'discussion_count',
            'CountComments' => 'comment_count',
        ];
        $filters = [
            'Name' => 'DeletedNameDuplicates',
            'Email' => 'BlankEmails',
        ];
        $query = $this->porterQB()->from('User')
            ->select()
            ->selectRaw('COALESCE(Confirmed, 1) as is_email_confirmed'); // Cannot be null.

        $this->import('users', $query, $this->getSchema('users'), $map, $filters);
    }

    /**
     * 'Groups' in Flarum. Flarum handles role assignment in a magic way.
     *
     * This compensates by shifting all RoleIDs +4, rendering any old 'Member' or 'Guest' role useless & deprecated.
     * @see https://docs.flarum.org/extend/permissions/
     */
    protected function roles(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('UserRole')) {
            Log::comment('Skipping import: Roles (Source lacks support)');
            $this->importEmpty('groups', $this->getSchema('groups'));
            $this->importEmpty('group_user', $this->getSchema('group_user'));
            return;
        }

        // Delete orphaned user role associations (deleted users).
        $this->pruneOrphanedRecords('UserRole', 'UserID', 'User', 'UserID');

        // Roles.
        $query = $this->porterQB()->from('Role')
            // Flarum reserves 1-3 & uses 4 for mods by default.
            ->selectRaw("(RoleID + 4) as id")
            // Singular vs plural is an uncommon feature; don't guess at it, just duplicate the Name.
            ->selectRaw('COALESCE(Name, CONCAT("role", RoleID)) as name_singular') // Cannot be null.
            ->selectRaw('COALESCE(Name, CONCAT("role", RoleID)) as name_plural') // Cannot be null.
            // Hiding roles is an uncommon feature; hide none.
            ->selectRaw('0 as is_hidden');
        $this->import('groups', $query, $this->getSchema('groups'));

        // User Roles.
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $this->porterQB()->from('UserRole')
            ->select(['UserID'])
            ->selectRaw("(RoleID + 4) as RoleID"); // Match above offset
        $this->import('group_user', $query, $this->getSchema('group_user'), $map);

        // Add defaults.
        $this->dbOutput()->table('groups')
            ->insertOrIgnore([
                ['id' => 1, 'name_singular' => 'Admin', 'name_plural' => 'Admins', 'is_hidden' => 0],
                ['id' => 2, 'name_singular' => 'Guest', 'name_plural' => 'Guests', 'is_hidden' => 0],
                ['id' => 3, 'name_singular' => 'Member', 'name_plural' => 'Members', 'is_hidden' => 0],
                // Not strictly necessary, just safer because Mod-level permissions may be in `group_user` already.
                ['id' => 4, 'name_singular' => 'Mod', 'name_plural' => 'Mods', 'is_hidden' => 0],
            ]);

        // Superadmin promotion.
        $this->promote();
    }

    /**
     * Promote a superadmin (User.Admin = 1) to the Flarum admin role.
     */
    protected function promote(): void
    {
        $result = $this->porterQB()->from('User')->where('Admin', '>', 0)->first();
        if (isset($result->Name, $result->Email) && !empty($result->UserID)) {
            $this->dbOutput()->table('group_user')->insert(['group_id' => 1, 'user_id' => $result->UserID]);
            Log::comment('Promoted to Admin: ' . $result->Name . ' (' . $result->Email . ')');
        } else {
            Log::comment('No user promoted to Admin (PORT_User.Admin=1 not found).');
        }
    }

    /**
     * 'Tags' in Flarum.
     */
    protected function categories(): void
    {
        $map = [
            'CategoryID' => 'id',
            'Name' => 'name',
            'Description' => 'description',
            'ParentCategoryID' => 'parent_id',
            'Sort' => 'position',
            'CountDiscussions' => 'discussion_count',
        ];
        $filters = [
            'CountDiscussions' => 'emptyToZero',
        ];
        $query = $this->porterQB()->from('Category')
            ->select()
            ->selectRaw('COALESCE(Name, CONCAT("category", CategoryID)) as name') // Cannot be null.
            ->selectRaw('COALESCE(UrlCode, CategoryID) as slug') // Cannot be null.
            ->selectRaw("if(ParentCategoryID = -1, null, ParentCategoryID) as ParentCategoryID")
            ->selectRaw("0 as is_hidden")
            ->selectRaw("0 as is_restricted")
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.

        $this->import('tags', $query, $this->getSchema('tags'), $map, $filters);
    }

    /**
     * Schema is variable depending on plugins.
     */
    protected function discussions(): void
    {
        $structure = $this->getDiscussionSchema(); // @see self::privateMessages()
        $map = [
            'DiscussionID' => 'id',
            'InsertUserID' => 'user_id',
            'Name' => 'title',
            'DateInserted' => 'created_at',
            'FirstCommentID' => 'first_post_id',
            'LastCommentID' => 'last_post_id',
            'DateLastComment' => 'last_posted_at',
            'LastCommentUserID' => 'last_posted_user_id',
            'CountComments' => 'comment_count',
            'Announce' => 'is_sticky', // Flarum doesn't mind if this is '2' so straight map it.
            'Closed' => 'is_locked',
        ];
        $filters = [
            'slug' => 'FormatUrl', // 'DiscussionID as slug' (below).
            'Announce' => 'emptyToZero',
            'Closed' => 'emptyToZero',
        ];

        // flarumite/simple-discussion-views
        if ($this->hasOutputSchema('discussions', ['view_count'])) {
            $structure['view_count'] = 'int';
            $map['CountViews'] = 'view_count';
            $filters['CountViews'] = 'emptyToZero';
        }

        // CountComments needs to be double-mapped so it's included as an alias also.
        $query = $this->porterQB()->from('Discussion')
            ->select()
            ->selectRaw('COALESCE(CountComments, 0) as post_number_index')
            ->selectRaw('CONCAT(DiscussionID, "-", Name) as slug')
            ->selectRaw('CountComments as last_post_number')
            ->selectRaw('0 as is_private')
            ->selectRaw('0 as votes')
            ->selectRaw('0 as hotness')
            ->selectRaw('1 as best_answer_notified');

        $this->import('discussions', $query, $structure, $map, $filters);

        // Discussion Tags pivot table.
        $map = [
            'DiscussionID' => 'discussion_id',
            'CategoryID' => 'tag_id',
        ];
        $query = $this->porterQB()->from('Discussion')
            ->select(['DiscussionID', 'CategoryID'])
            ->union(
                // Also tag discussion with the parent category.
                $this->dbPorter()
                    ->table('Discussion')
                    ->select(['DiscussionID'])
                    ->selectRaw('ParentCategoryID as CategoryID')
                    ->leftJoin('Category', 'Discussion.CategoryID', '=', 'Category.CategoryID')
                    ->whereNotNull('ParentCategoryID')
            );
        $this->import('discussion_tag', $query, $this->getSchema('discussion_tag'), $map, $filters);
    }

    /**
     * Requires addon `flarum/subscriptions`
     */
    protected function bookmarks(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('UserDiscussion')) {
            Log::comment('Skipping import: Bookmarks (Source lacks support)');
            return;
        }

        $map = [
            'DiscussionID' => 'discussion_id',
            'UserID' => 'user_id',
            'DateLastViewed' => 'last_read_at',
        ];
        $query = $this->porterQB()->from('UserDiscussion')
            ->select()
            ->selectRaw("if (Bookmarked > 0, 'follow', null) as subscription")
            ->where('UserID', '>', 0); // Vanilla can have zeroes here, can't remember why.
        $this->import('discussion_user', $query, $this->getSchema('discussion_user'), $map);
    }

    /**
     * 'Posts' in Flarum.
     */
    protected function comments(): void
    {
        $map = [
            'CommentID' => 'id',
            'DiscussionID' => 'discussion_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'edited_at',
            'UpdateUserID' => 'edited_user_id',
            'Body' => 'content'
        ];
        $filters = [
            'Body' => 'FlarumBody',
        ];
        $query = $this->porterQB()->from('Comment')
            // SELECT ORDER IS SENSITIVE DUE TO THE UNION() BELOW.
            ->select([
                'DiscussionID',
                'InsertUserID',
                'DateInserted',
                'DateUpdated',
                'UpdateUserID',
                'Body',
                'Format'])
            ->selectRaw('CommentID as CommentID')
            ->selectRaw('"comment" as type')
            ->selectRaw('null as number');

        // Extract OP from the discussion.
        if ($this->getDiscussionBodyMode()) {
            // Get highest CommentID.
            $result = $this->porterQB()
                ->from('Comment')
                ->selectRaw('max(CommentID) as LastCommentID')
                ->first();

            // Save value for other associations (e.g. attachments).
            $this->discussionPostOffset = $result->LastCommentID ?? 0;

            // Use DiscussionID but fast-forward it past highest CommentID to insure it's unique.
            $discussions = $this->porterQB()->from('Discussion')
                ->select([
                    'DiscussionID',
                    'InsertUserID',
                    'DateInserted',
                    'DateUpdated',
                    'UpdateUserID',
                    'Body',
                    'Format'])
                ->selectRaw('(DiscussionID + ' . $this->discussionPostOffset . ') as CommentID')
                ->selectRaw('"comment" as type')
                ->selectRaw('null as number');

            // Combine discussions.body with the comments to get all posts.
            $query->union($discussions);
        }

        $this->import('posts', $query, $this->getSchema('posts'), $map, $filters);
    }

    /**
     * Currently discards thumbnails because Flarum's extension doesn't have any.
     *
     * Requires discussions, comments, and PMs have imported.
     * @todo Support for `fof_upload_files.discussion_id` field, likely in Postscript (it's derived data).
     */
    protected function attachments(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('Media')) {
            Log::comment('Skipping import: Attachments (Source lacks support)');
            return;
        }

        $map = [
            'MediaID' => 'id',
            'InsertUserID' => 'actor_id',
            'Size' => 'size',
        ];
        $query = $this->porterQB()->from('Media')
            ->select()
            ->selectRaw('0 as discussion_id')
            ->selectRaw("concat('imported/', Path) as path")
            ->selectRaw("concat('/" . self::SUPPORTED['attachmentPath'] . "/',
                trim(leading '/' from COALESCE(Path, ''))) as url") // @todo Only a relative URL so far.
            // Untangle the Media.ForeignID & Media.ForeignTable [comment, discussion, message]
            ->selectRaw("case
                when ForeignID is null then 0
                when ForeignTable = 'comment' then ForeignID
                when ForeignTable = 'Comment' then ForeignID
                when ForeignTable = 'discussion' then ifnull((ForeignID + " . $this->discussionPostOffset . "), 0)
                when ForeignTable = 'embed' then 0
                when ForeignTable = 'message' then ifnull((ForeignID + " . $this->messagePostOffset . "), 0)
                end as post_id")
            ->selectRaw('"local" as upload_method')
            // MIME type cannot be null, so default to "application/octet-stream" as most generic default.
            ->selectRaw('COALESCE(Type, "application/octet-stream") as type')
            // fof_upload_files disallows null for base_name or created_at.
            ->selectRaw('COALESCE(RIGHT(Name, 220), "untitled") as base_name')
            ->selectRaw('COALESCE(DateInserted, NOW()) as created_at')
            // @see packages/upload/src/Providers/DownloadProvider.php
            ->selectRaw("case
                when Type like 'image/%' then 'image-preview'
                else 'file'
                end as tag");

        $this->import('fof_upload_files', $query, $this->getSchema('fof_upload_files'), $map);
    }

    /**
     * Requires addon `17development/flarum-user-badges`.
     */
    protected function badges(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('Badge')) {
            Log::comment('Skipping import: Badges (Source lacks support)');
            return;
        }

        // Badge Categories
        // One category is added in postscript.

        // Badges
        $map = [
            'Name' => 'name',
            'BadgeID' => 'id',
            'Body' => 'description',
            'Photo' => 'image',
            'Points' => 'points',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateLastViewed' => 'last_read_at',
            'Visible' => 'is_visible',
        ];
        $query = $this->porterQB()->from('Badge')
            ->select()
            ->selectRaw('1 as badge_category_id');
        $this->import('badges', $query, $this->getSchema('badges'), $map);

        // User Badges
        $map = [
            'BadgeID' => 'badge_id',
            'UserID' => 'user_id',
            'Reason' => 'description',
            'DateCompleted' => 'assigned_at',
        ];
        $query = $this->porterQB()->from('UserBadge')->select('*');
        $this->import('badge_user', $query, $this->getSchema('badge_user'), $map);

        // Add default badge category for all imported badges.
        if ($this->hasOutputSchema('badge_category')) {
            $this->dbOutput()
                ->table('badge_category')
                ->insertOrIgnore(['id' => 1, 'name' => 'Imported Badges', 'created_at' => date('Y-m-d h:m:s')]);
            Log::comment('Added badge category "Imported Badges".');
        }
    }

    /**
     * Requires addon `fof/polls`.
     */
    protected function polls(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('Poll')) {
            Log::comment('Skipping import: Polls (Source lacks support)');
            return;
        }

        // Polls
        $map = [
            'PollID' => 'id',
            'Name' => 'question',
            'DiscussionID' => 'discussion_id',
            'CommentID' => 'post_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'CountVotes' => 'vote_count',
        ];
        $filters = [
            'CountVotes' => 'emptyToZero',
        ];
        $query = $this->porterQB()->from('Poll')
            ->select(['*', 'DateInserted as end_date'])
            ->selectRaw('"{}" as settings') // cannot be null
            // Whether its public or anonymous are inverse conditions, so flip the value.
            ->selectRaw('if(Anonymous>0, 0, 1) as public_poll');
        $this->import('polls', $query, $this->getSchema('polls'), $map, $filters);

        // Poll Options
        $map = [
            'PollOptionID' => 'id',
            'PollID' => 'poll_id',
            'Body' => 'answer',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'CountVotes' => 'vote_count',
        ];
        $query = $this->porterQB()->from('PollOption')->select('*');
        $this->import('poll_options', $query, $this->getSchema('poll_options'), $map);

        // Poll Votes
        $map = [
            'PollOptionID' => 'option_id',
            'UserID' => 'user_id',
        ];
        $query = $this->porterQB()->from('PollVote')
            ->leftJoin('PollOption', 'PollVote.PollOptionID', '=', 'PollOption.PollOptionID')
            ->select(['PollVote.*',
                'PollOption.PollID as poll_id',
                'PollOption.DateInserted as created_at', // Total hack for approximate vote dates.
                'PollOption.DateUpdated as updated_at']);
        $this->import('poll_votes', $query, $this->getSchema('poll_votes'), $map);
    }

    /**
     * Requires addon `fof/reactions`.
     */
    public function reactions(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('ReactionType')) {
            Log::comment('Skipping import: Reactions (Source lacks support)');
            return;
        }

        // Reaction Types
        $map = [
            'TagID' => 'id',
            'Name' => 'identifier',
            //'Active' => 'enabled',
        ];
        $query = $this->porterQB()->from('ReactionType')
            // @todo Setting type='emoji' is a kludge since it won't render Vanilla defaults that way.
            ->select('*')
            ->selectRaw('COALESCE(Active, 1) as enabled')
            ->selectRaw('"emoji" as type');
        $this->import('reactions', $query, $this->getSchema('reactions'), $map);

        // Post Reactions
        $map = [
            'RecordID' => 'post_id',
            'UserID' => 'user_id',
            'TagID' => 'reaction_id',
            'DateInserted' => 'created_at',
        ];
        // SELECT ORDER IS SENSITIVE DUE TO THE UNION() BELOW.
        $query = $this->porterQB()->from('UserTag')
            ->select(['UserID', 'TagID'])
            ->selectRaw('RecordID as RecordID')
            ->selectRaw('TIMESTAMP(DateInserted) as DateInserted')
            ->where('RecordType', '=', 'Comment')
            ->where('UserID', '>', 0);

        // Get reactions for discussions (OPs).
        if ($this->getDiscussionBodyMode()) {
            // Get highest CommentID.
            $result = $this->porterQB()->from('Comment')
                ->selectRaw('max(CommentID) as LastCommentID')
                ->first();
            $lastCommentID = $result->LastCommentID ?? 0;

            /* @see Target\Flarum::comments() —  replicate our math in the post split */
            $discussionReactions = $this->porterQB()->from('UserTag')
                ->select(['UserID', 'TagID'])
                ->selectRaw('(RecordID + ' . $lastCommentID . ') as RecordID')
                ->selectRaw('TIMESTAMP(DateInserted) as DateInserted')
                ->where('RecordType', '=', 'Discussion')
                ->where('UserID', '>', 0);

            // Combine discussion reactions + comment reactions => post reactions.
            $query->union($discussionReactions);
        }

        $this->import('post_reactions', $query, $this->getSchema('post_reactions'), $map);
    }

    /**
     * Requires addon `fof/byobu`.
     * Export PMs to fof/byobu format, which uses the `posts` & `discussions` tables.
     */
    protected function conversations(): void
    {
        // Verify source support.
        if (!$this->hasPortSchema('Conversation')) {
            Log::comment('Skipping import: Private messages (Source lacks support)');
            return;
        }

        // Verify target support.
        if (!$this->hasOutputSchema('recipients')) {
            Log::comment('Skipping import: Private messages (Target lacks support - Enable the plugin first)');
            return;
        }

        // Messages — Discussions
        $MaxDiscussionID = $this->messageDiscussionOffset = $this->getMaxValue('id', 'discussions');
        Log::comment('Discussions offset for PMs is ' . $MaxDiscussionID);
        $structure = $this->getDiscussionSchema();
        $map = [
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
        ];

        // fof/gamification — no data, just prevent failure (no default value is set)
        if ($this->hasOutputSchema('discussions', ['votes'])) {
            $structure['votes'] = 'int';
        }

        $query = $this->porterQB()->from('Conversation')
            ->select(['InsertUserID', 'DateInserted'])
            ->selectRaw('(ConversationID + ' . $MaxDiscussionID . ') as id')
            ->selectRaw('DateInserted as last_posted_at') // @todo Orders old PMs by OP instead of last comment.
            ->selectRaw('0 as post_number_index')
            ->selectRaw('0 as is_sticky')
            ->selectRaw('0 as is_locked')
            ->selectRaw('1 as is_private')
            ->selectRaw('0 as votes') // Hedge against fof/gamification
            ->selectRaw('0 as hotness') // Hedge against fof/gamification
            ->selectRaw('0 as view_count')
            ->selectRaw('1 as best_answer_notified') // fof/best-answer
            ->selectRaw('(ConversationID + ' . $MaxDiscussionID . ') as slug')
            // Use a numbered title "Private discussion 1234" if there's no Subject line.
            ->selectRaw('ifnull(Subject,
                concat("Private discussion ", (ConversationID + ' . $MaxDiscussionID . '))) as title');

        $this->import('discussions', $query, $structure, $map);

        // Messages — Comments
        $MaxCommentID = $this->messagePostOffset = $this->getMaxValue('id', 'posts');
        Log::comment('Posts offset for PMs is ' . $MaxCommentID);
        $map = [
            'Body' => 'content',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
        ];
        $filters = [
            'Body' => 'FlarumBody',
        ];
        $query = $this->porterQB()->from('ConversationMessage')
            ->select(['Body', 'Format', 'InsertUserID', 'DateInserted'])
            ->selectRaw('(MessageID + ' . $MaxCommentID . ') as id')
            ->selectRaw('(ConversationID + ' . $MaxDiscussionID . ') as discussion_id')
            ->selectRaw('1 as is_private')
            ->selectRaw('"comment" as type');

        $this->import('posts', $query, $this->getSchema('posts'), $map, $filters);

        // Recipients
        $structure = [
            //'id' => 'int',
            'discussion_id' => 'int',
            'user_id' => 'int',
            //'group_id' => 'int',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
        $map = [
            'UserID' => 'user_id',
            'DateConversationUpdated' => 'updated_at',
        ];
        $query = $this->porterQB()->from('UserConversation')
            ->select(['UserID', 'DateConversationUpdated'])
            ->selectRaw('(ConversationID + ' . $MaxDiscussionID . ') as discussion_id');

        $this->import('recipients', $query, $structure, $map);
    }

    /**
     * Use Media.Path to set Media.TargetFullPath.
     */
    protected function mapAttachments(string $fileTarget): int
    {
        $rows = 0;
        $attachments = $this->porterQB()->from('Media')
            ->select(['MediaID'])
            // Reuse the filename in `Path` (not `Name`) in case it's been made guaranteed-unique.
            ->selectRaw("concat('{$fileTarget}/', Path) as TargetFullPath")
            // Assume we want the final Path if we got this far, so fix it.
            ->whereNotNull("Path")
            ->get();
        foreach ($attachments as $attachment) {
            $rows += $this->dbOutput()->affectingStatement("update `PORT_Media`
                set TargetFullPath = " . $this->dbOutput()->escape($attachment->TargetFullPath) . "
                where MediaID = {$attachment->MediaID}");  // @todo index needed?
        }

        return $rows;
    }

    /**
     * Use User.Photo to set Media.TargetAvatarFullPath.
     */
    protected function mapAvatars(string $fileTarget): int
    {
        $rows = 0;
        $avatars = $this->porterQB()->from('User')
            ->select(['UserID'])
            ->selectRaw("concat('{$fileTarget}', Photo) as TargetAvatarFullPath")
            // Local-file Photo should begin with a slash.
            ->whereNotNull("SourceAvatarFullPath") // 'Photo' could be a URL otherwise.
            ->get();
        foreach ($avatars as $avatar) {
            $rows += $this->dbOutput()->affectingStatement("update `PORT_User`
                    set TargetAvatarFullPath = " . $this->dbOutput()->escape($avatar->TargetAvatarFullPath) . "
                    where UserID = {$avatar->UserID}"); // @todo index needed?
        }

        return $rows;
    }
}
