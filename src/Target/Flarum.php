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
    public const SUPPORTED = [
        'name' => 'Flarum',
        'defaultTablePrefix' => 'FLA_',
        'avatarPath' => 'assets/avatars',
        'attachmentPath' => 'assets/files/imported',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 'tags',
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 'fof/polls',
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 'fof/byobu',
            'Attachments' => 'fof/uploads',
            'Bookmarks' => 'subscriptions',
            'Badges' => 'v17development/flarum-user-badges',
            'UserNotes' => 0,
            'Ranks' => 0,
            'Groups' => 0,
            'Tags' => 0,
            'Reactions' => 'fof/reactions',
        ]
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
        'fileTransferSupport' => true,
    ];

    /**
     * @var array Table structure for `posts`.
     * @see \Porter\Postscript\Flarum::numberPosts() for 'keys' requirement.
     */
    protected const DB_STRUCTURE_POSTS = [
        'id' => 'int',
        'discussion_id' => 'int',
        'user_id' => 'int',
        'created_at' => 'datetime',
        'edited_at' => 'datetime',
        'edited_user_id' => 'int',
        'type' => 'varchar(100)',
        'content' => 'longText',
        'number' => 'int',
        'keys' => [
            'FLA_posts_discussion_id_number_unique' => [
                'type' => 'unique',
                'columns' => ['discussion_id', 'number'],
            ],
            'FLA_posts_id_primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ]
        ],
    ];

    /**
     * @var array Table structure for 'discussions`.
     * @see \Porter\Postscript\Flarum::numberPosts() for 'keys' requirement.
     */
    protected const DB_STRUCTURE_DISCUSSIONS = [
        'id' => 'int',
        'user_id' => 'int',
        'title' => 'varchar(200)',
        'slug' => 'varchar(200)',
        'created_at' => 'datetime',
        'first_post_id' => 'int',
        'last_post_id' => 'int',
        'last_posted_at' => 'datetime',
        'last_posted_user_id' => 'int',
        'post_number_index' => 'int',
        'is_private' => 'tinyint', // fof/byobu (PMs)
        'is_sticky' => 'tinyint', // flarum/sticky
        'is_locked' => 'tinyint', // flarum/lock
        //'votes' => 'int', // fof/polls
        //'hotness' => 'double', // fof/gamification
        //'view_count' => 'int', // flarumite/simple-discussion-views
        //'best_answer_notified' => 'tinyint', // fof/best-answer
        'keys' => [
            'FLA_discussions_id_primary' => [
                'type' => 'primary',
                'columns' => ['id'],
            ]
        ],
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
        $this->uniqueUserNames();
        $this->uniqueUserEmails();
    }

    /**
     * @return array
     */
    protected function getStructureDiscussions()
    {
        $structure = self::DB_STRUCTURE_DISCUSSIONS;
        // fof/gamification — no data, just prevent failure (no default values are set)
        if ($this->hasOutputSchema('discussions', ['votes'])) {
            $structure['votes'] = 'int';
            $structure['hotness'] = 'double';
        }
        if ($this->hasOutputSchema('discussions', ['view_count'])) {
            $structure['view_count'] = 'int'; // flarumite/simple-discussion-views
        }
        return $structure;
    }

    /**
     * Flarum must have unique usernames. Report users skipped (because of `insert ignore`).
     *
     * Unsure this could get automated fix. You'd have to determine which has/have data attached and possibly merge.
     * You'd also need more data from findDuplicates, especially the IDs.
     * Folks are just gonna need to manually edit their existing forum data for now to rectify dupe issues.
     *
     * @throws \Exception
     */
    public function uniqueUserNames(): void
    {
        $allowlist = [
            '[Deleted User]',
            '[DeletedUser]',
            '-Deleted-User-',
            '[Slettet bruker]', // Norwegian
            '[Utilisateur supprimé]', // French
        ]; // @see fixDuplicateDeletedNames()
        $dupes = array_diff($this->findDuplicates('User', 'Name'), $allowlist);
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.name: ' . implode(', ', $dupes));
        }
    }

    /**
     * Flarum must have unique emails. Report users skipped (because of `insert ignore`).
     *
     * @throws \Exception
     * @see uniqueUserNames
     *
     */
    public function uniqueUserEmails(): void
    {
        $dupes = $this->findDuplicates('User', 'Email');
        if (!empty($dupes)) {
            Log::comment('DATA LOSS! Users skipped for duplicate user.email: ' . implode(', ', $dupes));
        }
    }

    /**
     * Main import process.
     */
    public function run(): void
    {
        // Ignore constraints on tables that block import.
        $this->ignoreOutputDuplicates('users');

        // Map a file transfer.
        $this->mapFileTransfer();

        $this->users();
        $this->roles(); // 'Groups' in Flarum
        $this->categories(); // 'Tags' in Flarum

        // Singleton factory; big timing issue; depends on Users being done.
        Formatter::instance()->buildUserMap($this); // @todo Hook for pre-UGC import?

        $this->discussions();
        $this->bookmarks(); // Requires addon `flarum/subscriptions`
        $this->comments(); // 'Posts' in Flarum

        $this->badges(); // Requires addon `17development/flarum-user-badges`
        $this->polls(); // Requires addon `fof/pollsx`
        $this->reactions(); // Requires addon `fof/reactions`

        $this->privateMessages(); // Requires addon `fof/byobu`

        $this->attachments(); // Requires discussions, comments, and PMs have imported.
    }

    /**
     */
    protected function users(): void
    {
        $structure = [
            'id' => 'int',
            'username' => 'varchar(100)',
            'email' => 'varchar(100)',
            'is_email_confirmed' => 'tinyint',
            'password' => 'varchar(100)',
            'avatar_url' => 'varchar(100)',
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'discussion_count' => 'int',
            'comment_count' => 'int',
        ];
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
            'Name' => 'fixDuplicateDeletedNames',
            'Email' => 'fixNullEmails',
        ];
        $query = $this->porterQB()
            ->from('User')
            ->select()
            ->selectRaw('COALESCE(Confirmed, 1) as is_email_confirmed'); // Cannot be null.

        $this->import('users', $query, $structure, $map, $filters);
    }

    /**
     * Flarum handles role assignment in a magic way.
     *
     * This compensates by shifting all RoleIDs +4, rendering any old 'Member' or 'Guest' role useless & deprecated.
     * @see https://docs.flarum.org/extend/permissions/
     *
     */
    protected function roles(): void
    {
        $structure = [
            'id' => 'int',
            'name_singular' => 'varchar(100)',
            'name_plural' => 'varchar(100)',
            'color' => 'varchar(20)',
            'icon' => 'varchar(100)',
            'is_hidden' => 'tinyint',
        ];
        $map = [];

        // Verify support.
        if (!$this->hasPortSchema('UserRole')) {
            Log::comment('Skipping import: Roles (Source lacks support)');
            $this->importEmpty('groups', $structure);
            $this->importEmpty('group_user', $structure);
            return;
        }

        // Delete orphaned user role associations (deleted users).
        $this->pruneOrphanedRecords('UserRole', 'UserID', 'User', 'UserID');

        $query = $this->porterQB()
            ->from('Role')
            // Flarum reserves 1-3 & uses 4 for mods by default.
            ->selectRaw("(RoleID + 4) as id")
            // Singular vs plural is an uncommon feature; don't guess at it, just duplicate the Name.
            ->selectRaw('COALESCE(Name, CONCAT("role", RoleID)) as name_singular') // Cannot be null.
            ->selectRaw('COALESCE(Name, CONCAT("role", RoleID)) as name_plural') // Cannot be null.
            // Hiding roles is an uncommon feature; hide none.
            ->selectRaw('0 as is_hidden');

        $this->import('groups', $query, $structure, $map);

        // User Role.
        $structure = [
            'user_id' => 'int',
            'group_id' => 'int',
        ];
        $map = [
            'UserID' => 'user_id',
            'RoleID' => 'group_id',
        ];
        $query = $this->porterQB()
            ->from('UserRole')
            ->select()
            ->selectRaw("(RoleID + 4) as RoleID"); // Match above offset

        $this->import('group_user', $query, $structure, $map);
    }

    /**
     */
    protected function categories(): void
    {
        $structure = [
            'id' => 'int',
            'name' => 'varchar(100)',
            'slug' => 'varchar(100)',
            'description' => 'text',
            'parent_id' => 'int',
            'position' => 'int',
            'discussion_count' => 'int',
            'is_hidden' => 'tinyint',
            'is_restricted' => 'tinyint',
        ];
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
        $query = $this->porterQB()
            ->from('Category')
            ->select()
            ->selectRaw('COALESCE(Name, CONCAT("category", CategoryID)) as name') // Cannot be null.
            ->selectRaw('COALESCE(UrlCode, CategoryID) as slug') // Cannot be null.
            ->selectRaw("if(ParentCategoryID = -1, null, ParentCategoryID) as ParentCategoryID")
            ->selectRaw("0 as is_hidden")
            ->selectRaw("0 as is_restricted")
            ->where('CategoryID', '!=', -1); // Ignore Vanilla's root category.

        $this->import('tags', $query, $structure, $map, $filters);
    }

    /**
     */
    protected function discussions(): void
    {
        $structure = $this->getStructureDiscussions();
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
            'slug' => 'createDiscussionSlugs', // 'DiscussionID as slug' (below).
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
        $query = $this->porterQB()
            ->from('Discussion')
            ->select()
            ->selectRaw('COALESCE(CountComments, 0) as post_number_index')
            ->selectRaw('DiscussionID as slug')
            ->selectRaw('CountComments as last_post_number')
            ->selectRaw('0 as votes')
            ->selectRaw('0 as hotness')
            ->selectRaw('1 as best_answer_notified');

        $this->import('discussions', $query, $structure, $map, $filters);

        // Flarum has a separate pivot table for discussion tags.
        $structure = [
            'discussion_id' => 'int',
            'tag_id' => 'int',
        ];
        $map = [
            'DiscussionID' => 'discussion_id',
            'CategoryID' => 'tag_id',
        ];
        $query = $this->porterQB()
            ->from('Discussion')
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

        $this->import('discussion_tag', $query, $structure, $map, $filters);
    }

    /**
     */
    protected function bookmarks(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('UserDiscussion')) {
            Log::comment('Skipping import: Bookmarks (Source lacks support)');
            return;
        }

        $structure = [
            'discussion_id' => 'int',
            'user_id' => 'int',
            'last_read_at' => 'datetime',
            'subscription' => [null, 'follow', 'ignore'],
            'last_read_post_number' => 'int',
            'keys' => [
                'FLA_discussion_user_discussion_id_foreign' => [
                    'type' => 'index',
                    'columns' => ['discussion_id'],
                ],
            ],
        ];
        $map = [
            'DiscussionID' => 'discussion_id',
            'UserID' => 'user_id',
            'DateLastViewed' => 'last_read_at',
        ];
        $query = $this->porterQB()
            ->from('UserDiscussion')
            ->select()
            ->selectRaw("if (Bookmarked > 0, 'follow', null) as subscription")
            ->where('UserID', '>', 0); // Vanilla can have zeroes here, can't remember why.

        $this->import('discussion_user', $query, $structure, $map);
    }

    /**
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
            'Body' => 'filterFlarumContent',
        ];
        $query = $this->porterQB()
            ->from('Comment')
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

        $this->import('posts', $query, self::DB_STRUCTURE_POSTS, $map, $filters);
    }

    /**
     * Currently discards thumbnails because Flarum's extension doesn't have any.
     *
     * @todo Support for `fof_upload_files.discussion_id` field, likely in Postscript (it's derived data).
     *
     */
    protected function attachments(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('Media')) {
            Log::comment('Skipping import: Attachments (Source lacks support)');
            return;
        }

        $structure = [
            'id' => 'int',
            'actor_id' => 'int',
            'discussion_id' => 'int',
            'post_id' => 'int',
            'base_name' => 'varchar(255)', // "download as"
            'path' => 'varchar(255)', // from /forumroot/assets/files
            'url' => 'varchar(255)',
            'type' => 'varchar(255)', // MIME
            'size'  => 'int', // bytes
            'created_at' => 'datetime',
            'upload_method' => 'varchar(255)', // Probably just 'local'
            'tag' => 'varchar(255)', // Required; generates preview in Profile -> "My Media"
        ];
        $map = [
            'MediaID' => 'id',
            'Name' => 'base_name',
            'InsertUserID' => 'actor_id',
            'DateInserted' => 'created_at',
            'Size' => 'size',
        ];
        $query = $this->porterQB()->from('Media')
            ->select()
            ->selectRaw('0 as discussion_id')
            ->selectRaw("concat('imported/', Path) as path")
            ->selectRaw("concat('/" . self::SUPPORTED['attachmentPath'] . "/',
                trim(leading '/' from Path)) as url") // @todo Only a relative URL so far.
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
            // fof_upload_files disallows null for base_name.
            ->selectRaw('COALESCE(Name, "untitled") as base_name')
            // @see packages/upload/src/Providers/DownloadProvider.php
            ->selectRaw("case
                when Type like 'image/%' then 'image-preview'
                else 'file'
                end as tag");

        $this->import('fof_upload_files', $query, $structure, $map);
    }

    /**
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
        $structure = [
            'id' => 'int',
            'name' => 'varchar(200)',
            'image' => 'text',
            'description' => 'text',
            'badge_category_id' => 'int',
            'points' => 'int',
            'created_at' => 'datetime',
            'is_visible' => 'tinyint',
        ];
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

        $this->import('badges', $query, $structure, $map);

        // User Badges
        $structure = [
            'badge_id' => 'int',
            'user_id' => 'int',
            'assigned_at' => 'datetime',
            'description' => 'text',
        ];
        $map = [
            'BadgeID' => 'badge_id',
            'UserID' => 'user_id',
            'Reason' => 'description',
            'DateCompleted' => 'assigned_at',
        ];
        $query = $this->porterQB()->from('UserBadge')->select('*');

        $this->import('badge_user', $query, $structure, $map);
    }

    /**
     */
    protected function polls(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('Poll')) {
            Log::comment('Skipping import: Polls (Source lacks support)');
            return;
        }

        // Polls
        $structure = [
            'id' => 'int',
            'question' => 'varchar(200)',
            'discussion_id' => 'int',
            'user_id' => 'int',
            'public_poll' => 'tinyint', // Map to "Anonymous" somehow?
            'end_date' => 'datetime', // Using date created here will close all polls, but work fine.
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'vote_count' => 'int',
        ];
        $map = [
            'PollID' => 'id',
            'Name' => 'question',
            'DiscussionID' => 'discussion_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'CountVotes' => 'vote_count',
        ];
        $query = $this->porterQB()->from('Poll')
            ->select('*')
            ->select('DateInserted as end_date')
                // Whether its public or anonymous are inverse conditions, so flip the value.
            ->selectRaw('if(Anonymous>0, 0, 1) as public_poll');

        $this->import('polls', $query, $structure, $map);

        // Poll Options
        $structure = [
            'id' => 'int',
            'answer' => 'varchar(200)',
            'poll_id' => 'int',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'vote_count' => 'int',
        ];
        $map = [
            'PollOptionID' => 'id',
            'PollID' => 'poll_id',
            'Body' => 'answer',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'CountVotes' => 'vote_count',
        ];
        $query = $this->porterQB()->from('PollOption')->select('*');

        $this->import('poll_options', $query, $structure, $map);

        // Poll Votes
        $structure = [
            //id
            'poll_id' => 'int',
            'option_id' => 'int',
            'user_id' => 'int',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
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

        $this->import('poll_votes', $query, $structure, $map);
    }

    /**
     */
    public function reactions(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('ReactionType')) {
            Log::comment('Skipping import: Reactions (Source lacks support)');
            return;
        }

        // Reaction Types
        $structure = [
            'id' => 'int',
            'identifier' => 'varchar(200)',
            'type' => 'varchar(200)',
            'enabled' => 'tinyint',
            'display' => 'varchar(200)',
        ];
        $map = [
            'TagID' => 'id',
            'Name' => 'identifier',
            'Active' => 'enabled',
        ];
        $query = $this->porterQB()->from('ReactionType')
            // @todo Setting type='emoji' is a kludge since it won't render Vanilla defaults that way.
            ->select('*')
            ->selectRaw('"emoji" as type');

        $this->import('reactions', $query, $structure, $map);

        // Post Reactions
        $structure = [
            'id' => 'int',
            'post_id' => 'int',
            'user_id' => 'int',
            'reaction_id' => 'int',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
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
            $result = $this->porterQB()
                ->from('Comment')
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

        $this->import('post_reactions', $query, $structure, $map);
    }

    /**
     * Export PMs to fof/byobu format, which uses the `posts` & `discussions` tables.
     *
     */
    protected function privateMessages(): void
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
        $structure = $this->getStructureDiscussions();
        $map = [
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
        ];
        $filters = [
            'slug' => 'createDiscussionSlugs',
        ];

        // fof/gamification — no data, just prevent failure (no default value is set)
        if ($this->hasOutputSchema('discussions', ['votes'])) {
            $structure['votes'] = 'int';
        }

        $query = $this->porterQB()->from('Conversation')
            ->select(['InsertUserID', 'DateInserted'])
            ->selectRaw('(ConversationID + ' . $MaxDiscussionID . ') as id')
            ->selectRaw('DateInserted as last_posted_at') // @todo Orders old PMs by OP instead of last comment.
            ->selectRaw('1 as is_private')
            ->selectRaw('0 as votes') // Hedge against fof/gamification
            ->selectRaw('0 as hotness') // Hedge against fof/gamification
            ->selectRaw('0 as view_count')
            ->selectRaw('(ConversationID + ' . $MaxDiscussionID . ') as slug')
            // Use a numbered title "Private discussion 1234" if there's no Subject line.
            ->selectRaw('ifnull(Subject,
                concat("Private discussion ", (ConversationID + ' . $MaxDiscussionID . '))) as title');

        $this->import('discussions', $query, $structure, $map, $filters);

        // Messages — Comments
        $MaxCommentID = $this->messagePostOffset = $this->getMaxValue('id', 'posts');
        Log::comment('Posts offset for PMs is ' . $MaxCommentID);
        $map = [
            'Body' => 'content',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
        ];
        $filters = [
            'Body' => 'filterFlarumContent',
        ];
        $query = $this->porterQB()->from('ConversationMessage')
            ->select(['Body', 'Format', 'InsertUserID', 'DateInserted'])
            ->selectRaw('(MessageID + ' . $MaxCommentID . ') as id')
            ->selectRaw('(ConversationID + ' . $MaxDiscussionID . ') as discussion_id')
            ->selectRaw('1 as is_private')
            ->selectRaw('"comment" as type');

        $this->import('posts', $query, self::DB_STRUCTURE_POSTS, $map, $filters);

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
     * Setup the destination values for FileTransfer.
     *
     * Set PORT_Media.TargetFullPath and PORT_User.TargetAvatarFullPath
     *
     */
    public function mapFileTransfer(): void
    {
        // Abort if we lack support.
        if (!$this->getFileTransferSupport()) {
            return;
        }

        // Start timer.
        $start = microtime(true);
        $rows = 0;
        Log::comment("Mapping attachments...");

        // Media.Path
        if ($fileTarget = $this->getPath('attachment', 'full')) {
            $attachments = $this->porterQB()->from('Media')
                ->select(['MediaID'])
                // Reuse the filename in `Path` (not `Name`) in case it's been made guaranteed-unique.
                ->selectRaw("concat('{$fileTarget}/', Path) as TargetFullPath")
                // Assume we want the final Path if we got this far, so fix it.
                ->whereNotNull("Path")
                ->get();
            $memory = memory_get_usage();
            foreach ($attachments as $attachment) {
                $rows += $this->dbOutput()->affectingStatement("update `PORT_Media`
                    set TargetFullPath = " . $this->dbOutput()->escape($attachment->TargetFullPath) . "
                    where MediaID = {$attachment->MediaID}");  // @todo index needed?
            }
            // Report.
            Log::storage(
                'map',
                'Media.TargetFullPath',
                microtime(true) - $start,
                $rows,
                $memory
            );
        }

        // Start timer.
        $start = microtime(true);
        $rows = 0;
        Log::comment("Mapping avatars...");

        // User.Photo
        if ($fileTarget = $this->getPath('avatar', 'full')) {
            $avatars = $this->porterQB()->from('User')
                ->select(['UserID'])
                ->selectRaw("concat('{$fileTarget}', Photo) as TargetAvatarFullPath")
                    // Local-file Photo should begin with a slash.
                ->whereNotNull("SourceAvatarFullPath") // 'Photo' could be a URL otherwise.
                ->get();
            $memory = memory_get_usage();
            foreach ($avatars as $avatar) {
                $rows += $this->dbOutput()->affectingStatement("update `PORT_User`
                    set TargetAvatarFullPath = " . $this->dbOutput()->escape($avatar->TargetAvatarFullPath) . "
                    where UserID = {$avatar->UserID}"); // @todo index needed?
            }
            // Report.
            Log::storage(
                'map',
                'User.TargetAvatarFullPath',
                microtime(true) - $start,
                $rows,
                $memory
            );
        }
    }
}
