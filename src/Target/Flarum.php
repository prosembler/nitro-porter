<?php

/**
 *
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Target;

use Porter\Component;
use Porter\Filter;
use Porter\Log;
use Porter\Formatter;
use Porter\Target;
use Porter\Transformation;

/**
 * You'll notice a seemingly random mix of datetime and timestamp in the Flarum database.
 * Synch0, 2022-08-01:
 * > Back in 2014-16, the default was datetime, but then Laravel switched to timestamp by default.
 */
class Flarum extends Target
{
    public const array INFO = [
        'name' => 'Flarum',
        'defaultTablePrefix' => 'FLA_',
        'avatarPath' => 'assets/avatars',
        'attachmentPath' => 'assets/files/imported',
    ];

    public const array FEATURE_REQUIREMENTS = [
        'categories' => ['enabled' => 'tags'],
        'polls' => ['enabled' => 'fof/polls'],
        'conversations' => ['enabled' => 'fof/byobu'],
        'attachments' => ['enabled' => 'fof/uploads'],
        'bookmarks' => ['enabled' => 'subscriptions'],
        'badges' => ['enabled' => 'v17development/flarum-user-badges'],
        'reactions' => ['enabled' => 'fof/reactions'],
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
        'fileTransferSupport' => true,
    ];

    /** @var int Offset for inserting OP content into the posts table. */
    protected int $postOffset = 0;

    /** @var int Offset for inserting PMs into posts table. */
    protected int $messagePostOffset = 0;

    /** Standard precheck for issues that will break the import. */
    public function validate(): void
    {
        // Flarum must have unique usernames. Report users skipped (because of `insert ignore`).
        // Unsure fix could be automated. Manually edit existing forum data for now to rectify dupe issues.
        // Would need to find data attached & possibly merge. Would need IDs etc from findDuplicates().
        $dupes = array_diff($this->findDuplicates('User', 'Name'), Formatter::DELETED_USERNAMES);
        if (!empty($dupes)) {
            Log::comment('[DATA LOSS] Users skipped for duplicate user.name: ' . implode(', ', $dupes));
        }

        // Flarum must have unique emails. Report users skipped (because of `insert ignore`).
        $dupes = $this->findDuplicates('User', 'Email');
        if (!empty($dupes)) {
            Log::comment('[DATA LOSS] Users skipped for duplicate user.email: ' . implode(', ', $dupes));
        }
    }

    /** First step. */
    protected function setup(): void
    {
        // Ignore constraints on tables that block import.
        $this->ignoreOutputDuplicates('users');
        $this->ignoreOutputDuplicates('groups');
        $this->ignoreOutputDuplicates('badge_category');
    }

    /** Last step. */
    protected function cleanup(): void
    {
        // Superadmin promotion.
        $this->promoteFlarumAdmin();
        // Empty access tokens for a fresh forum.
        if ($this->dbOutput()->getSchemaBuilder()->hasTable('access_tokens')) {
            $this->dbOutput()->table('access_tokens')->truncate();
        }
    }

    /** Step after users & taxonomy, but before posts etc. */
    protected function precontent(): void
    {
        // Singleton factory; depends on Users being done.
        Formatter::instance()->buildUserMap($this);
    }

    /** Promote a superadmin (User.Admin = 1) to the Flarum admin role. */
    protected function promoteFlarumAdmin(): void
    {
        $result = $this->porterQB()->from('User')->where('Admin', '>', 0)->first();
        if (isset($result->Name, $result->Email) && !empty($result->UserID)) {
            $this->dbOutput()->table('group_user')->insert(['group_id' => 1, 'user_id' => $result->UserID]);
            Log::comment('Promoted to Admin: ' . $result->Name . ' (' . $result->Email . ')');
        } else {
            Log::comment('No user promoted to Admin (PORT_User.Admin=1 not found).');
        }
    }

    /** Duplicated logic between discussions() and privateMessages() for Flarum plugin reasons. */
    protected function getFlarumDiscussionSchema(): array
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

    protected function users(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'users',
                data: 'User',
                map: [
                    'UserID' => 'id',
                    'Name' => 'username',
                    'Email' => 'email',
                    'Password' => 'password',
                    'Photo' => 'avatar_url',
                    'DateInserted' => 'joined_at',
                    'DateLastActive' => 'last_seen_at',
                    'CountDiscussions' => 'discussion_count',
                    'CountComments' => 'comment_count',
                    'Confirmed' => 'is_email_confirmed',
                ],
                filters: [
                    'Name' => \Porter\Filter\DeletedNameDuplicates::class,
                    'Email' => \Porter\Filter\BlankEmails::class,
                    'Confirmed' => fn($val, $name, $row) => (empty($val)) ? 1 : $val, // COALESCE(Confirmed, 1)
                ],
            )
        ]);
    }

    /**
     * 'Groups' in Flarum. Flarum handles role assignment in a magic way.
     *
     * This compensates by shifting all RoleIDs +4, rendering any old 'Member' or 'Guest' role useless & deprecated.
     * @see https://docs.flarum.org/extend/permissions/
     */
    protected function roles(): Component
    {
        // Delete orphaned user role associations (deleted users).
        $this->pruneOrphanedRecords('UserRole', 'UserID', 'User', 'UserID');

        return new Component([
            new Transformation(
                outputSchemaName: 'groups',
                data: 'Role',
                map: [
                    'Name' => ['name_singular', 'name_plural'], // Singular vs plural is uncommon; just duplicate Name.
                    'RoleID' => 'id',
                    'is_hidden=0', // Hiding roles is an uncommon feature; hide none.
                ],
                filters: [
                    'Name' => fn($val, $name, $row) => $val ?? 'role' . $row['RoleID'], // Cannot be null.
                    'RoleID' => fn($val, $name, $row) => $val + 4, // Flarum reserves 1-3 & uses 4 for mods by default.
                ]
            ),
            new Transformation( // User Roles => Group Users.
                outputSchemaName: 'group_user',
                data: 'UserRole',
                map: [
                    'UserID' => 'user_id',
                    'RoleID' => 'group_id',
                ],
                filters: [
                    'RoleID' => fn($val, $name, $row) => $val + 4, // Match above offset
                ]
            ),
            new Transformation( // Default groups.
                outputSchemaName: 'groups',
                data: [
                    ['id' => 1, 'name_singular' => 'Admin', 'name_plural' => 'Admins', 'is_hidden' => 0],
                    ['id' => 2, 'name_singular' => 'Guest', 'name_plural' => 'Guests', 'is_hidden' => 0],
                    ['id' => 3, 'name_singular' => 'Member', 'name_plural' => 'Members', 'is_hidden' => 0],
                    // Not strictly necessary, just safer because Mod-level permissions may be in `group_user` already.
                    ['id' => 4, 'name_singular' => 'Mod', 'name_plural' => 'Mods', 'is_hidden' => 0],
                ],
            ),
        ]);
    }

    /** 'Tags' in Flarum. */
    protected function categories(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'tags',
                data: $this->porterQB()->from('Category')->select()
                    ->where('CategoryID', '!=', -1), // Ignore Vanilla's root category.
                map: [
                    'CategoryID' => 'id',
                    'Name' => 'name',
                    'Description' => 'description',
                    'ParentCategoryID' => 'parent_id',
                    'Sort' => 'position',
                    'CountDiscussions' => 'discussion_count',
                    'is_hidden=0',
                    'is_restricted=0',
                    'UrlCode' => 'slug',
                ],
                filters: [
                    'CountDiscussions' => \Porter\Filter\EmptyToZero::class,
                    'Name' => fn($val, $name, $row) => $val ?? 'category' . $row['CategoryID'], // Cannot be null.
                    'UrlCode' => fn($val, $name, $row) => $val ?? $row['CategoryID'], // Cannot be null.
                    'ParentCategoryID' => fn($val, $name, $row) => (-1 === $val) ? null : $val, // Root cat is null ID.
                ],
            ),
        ]);
    }

    /**
     * Schema is variable depending on plugins.
     */
    protected function discussions(): void
    {
        $this->setSchema('discussions', $this->getFlarumDiscussionSchema()); // @see self::privateMessages()
        $map = [
            'DiscussionID' => 'id',
            'InsertUserID' => 'user_id',
            'Name' => 'title',
            'DateInserted' => 'created_at',
            'FirstCommentID' => 'first_post_id',
            'LastCommentID' => 'last_post_id',
            'DateLastComment' => 'last_posted_at',
            'LastCommentUserID' => 'last_posted_user_id',
            'CountComments' => ['comment_count', 'last_post_number', 'post_number_index'],
            'Announce' => 'is_sticky', // Flarum doesn't mind if this is '2' so straight map it.
            'Closed' => 'is_locked',
            'CountViews' => 'view_count', // flarumite/simple-discussion-views
            'is_private=0',
            'votes=0',
            'hotness=0',
            'best_answer_notified=1',
        ];
        $filters = [
            'slug' => function ($val, $col, $row) {
                $filter = new Filter\FormatUrl($row['DiscussionID'] . '-' . $row['Name'], $col, $row);
                return $filter();
            },
            'Announce' => \Porter\Filter\EmptyToZero::class,
            'Closed' => \Porter\Filter\EmptyToZero::class,
            'CountViews' => \Porter\Filter\EmptyToZero::class, // flarumite/simple-discussion-views
            'CountComments' => \Porter\Filter\EmptyToZero::class,
        ];
        $query = $this->porterQB()->from('Discussion')->select();
        $this->import('discussions', $query, $map, $filters);

        // Discussion Tags pivot table.
        $map = [
            'DiscussionID' => 'discussion_id',
            'CategoryID' => 'tag_id',
        ];
        $query = $this->porterQB()->from('Discussion')->select();
        $this->import('discussion_tag', $query, $map);

        // Also tag discussion with the parent category.
        $map = [
            'DiscussionID' => 'discussion_id',
            'ParentCategoryID' => 'tag_id',
        ];
        $query = $this->porterQB()->from('Discussion')->select()
            ->leftJoin('Category', 'Discussion.CategoryID', '=', 'Category.CategoryID')
            ->whereNotNull('ParentCategoryID');
        $this->import('discussion_tag', $query, $map);
    }

    /** Requires addon `flarum/subscriptions` */
    protected function bookmarks(): Component
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'discussion_user',
                data: $this->porterQB()->from('UserDiscussion')->select()
                    ->where('UserID', '>', 0), // Vanilla can have zeroes here, can't remember why.
                map: [
                    'DiscussionID' => 'discussion_id',
                    'UserID' => 'user_id',
                    'DateLastViewed' => 'last_read_at',
                    'Bookmarked' => 'subscription',
                ],
                filters: [
                    'Bookmarked' => fn($val, $name, $row) => $val > 0 ? 'follow' : null,
                ],
            ),
        ]);
    }

    /** 'Posts' in Flarum. */
    protected function comments(): void
    {
        $map = [
            'CommentID' => 'id',
            'DiscussionID' => 'discussion_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'edited_at',
            'UpdateUserID' => 'edited_user_id',
            'Body' => 'content',
            'type=comment',
        ];
        $filters = [
            'Body' => \Porter\Filter\FlarumBody::class,
        ];
        $query = $this->porterQB()->from('Comment')->select();
        $this->import('posts', $query, $map, $filters);

        // Extract OP from the discussion.
        if ($this->useDiscussionBody()) {
            // Get highest CommentID & save for other associations (e.g. attachments)
            $this->postOffset = $this->porterQB()->from('Comment')->max('CommentID')->CommentID ?? 0;
            // Also map DiscussionID to 'id'.
            $map['DiscussionID'] = ['discussion_id', 'id'];
            // Use DiscussionID but fast-forward it past highest CommentID to insure it's unique.
            $filters['id'] = fn($val, $col, $row) => $val + $this->postOffset;
            $query = $this->porterQB()->from('Discussion')->select();
            $this->import('posts', $query, $map, $filters);
        }
    }

    /**
     * Currently discards thumbnails because Flarum's extension doesn't have any.
     *
     * Requires discussions, comments, and PMs have imported.
     * @todo Support for `fof_upload_files.discussion_id` field, likely in Postscript (it's derived data).
     */
    protected function attachments(): void
    {
        $map = [
            'MediaID' => 'id',
            'InsertUserID' => 'actor_id',
            'Size' => 'size',
            'discussion_id=0',
            'upload_method=local',
            'ForeignID' => 'post_id',
            'Type' => 'type',
            'Path' => 'path',
            'Name' => 'base_name',
        ];
        $filters = [
            'ForeignID' => function ($val, $col, $row) {
                return match (true) { // Untangle Media.ForeignID/ForeignTable [comment, discussion, message]
                    ('comment' === strtolower($row['ForeignTable'])) => $val,
                    ('discussion' === strtolower($row['ForeignTable'])) => $val + $this->postOffset,
                    ('message' === strtolower($row['ForeignTable'])) => $val + $this->messagePostOffset,
                    default => 0, // (null === $val || 'embed' === $row['ForeignTable']) => 0,
                };
            },
            // MIME type cannot be null, so default to "application/octet-stream" as most generic default.
            'Type' => fn ($val, $col, $row) => $val ?? "application/octet-stream",
            'Path' => fn ($val, $col, $row) => 'imported/' . $val,
            // fof_upload_files disallows null for base_name or created_at.
            'Name' => fn ($val, $col, $row) => !empty(substr($val, -220)) ? substr($val, -220) : 'untitled',
            'DateInserted' => Filter\EmptyToDate::class,
            // @todo 'url' can only be a relative path today.
            'url' => fn ($val, $col, $row) => '/' .  self::INFO['attachmentPath'] . '/' . ltrim($row['Path'], '/'),
            // @see packages/upload/src/Providers/DownloadProvider.php
            'tag' => fn ($val, $col, $row) => (str_starts_with($row['Type'], 'image/')) ? 'image-preview' : 'file',
        ];
        $query = $this->porterQB()->from('Media')->select();
        $this->import('fof_upload_files', $query, $map, $filters);
    }

    /** Requires addon `17development/flarum-user-badges`. */
    protected function badges(): Component|null
    {
        return new Component([
            new Transformation(
                outputSchemaName: 'badges',
                data: 'Badge',
                map: [
                    'Name' => 'name',
                    'BadgeID' => 'id',
                    'Body' => 'description',
                    'Photo' => 'image',
                    'Points' => 'points',
                    'InsertUserID' => 'user_id',
                    'DateInserted' => 'created_at',
                    'DateLastViewed' => 'last_read_at',
                    'Visible' => 'is_visible',
                    'badge_category_id=1',
                ],
            ),
            new Transformation(
                outputSchemaName: 'badge_user',
                data: 'UserBadge',
                map: [
                    'BadgeID' => 'badge_id',
                    'UserID' => 'user_id',
                    'Reason' => 'description',
                    'DateCompleted' => 'assigned_at',
                ],
            ),
            new Transformation( // Add default badge category for all imported badges.
                outputSchemaName: 'badge_category',
                data: ['id' => 1, 'name' => 'Imported Badges', 'created_at' => date('Y-m-d h:m:s')],
            ),
        ]);
    }

    /** Requires addon `fof/polls`. */
    protected function polls(): void
    {
        $map = [
            'PollID' => 'id',
            'Name' => 'question',
            'DiscussionID' => 'discussion_id',
            'CommentID' => 'post_id',
            'InsertUserID' => 'user_id',
            'DateInserted' => ['created_at', 'end_date'],
            'DateUpdated' => 'updated_at',
            'CountVotes' => 'vote_count',
            'settings={}', // cannot be null
            'Anonymous' => 'public_poll',
        ];
        $filters = [
            'CountVotes' => \Porter\Filter\EmptyToZero::class,
            // Whether its public or anonymous are inverse conditions, so flip the value.
            'Anonymous' => fn($val, $col, $row) => $val > 0 ? 0 : 1,
        ];
        $query = $this->porterQB()->from('Poll')->select();
        $this->import('polls', $query, $map, $filters);

        // Poll Options
        $map = [
            'PollOptionID' => 'id',
            'PollID' => 'poll_id',
            'Body' => 'answer',
            'DateInserted' => 'created_at',
            'DateUpdated' => 'updated_at',
            'CountVotes' => 'vote_count',
        ];
        $query = $this->porterQB()->from('PollOption')->select();
        $this->import('poll_options', $query, $map);

        // Poll Votes
        $map = [
            'PollOptionID' => 'option_id',
            'UserID' => 'user_id',
        ];
        $query = $this->porterQB()->from('PollVote')
            ->leftJoin('PollOption', 'PollVote.PollOptionID', '=', 'PollOption.PollOptionID')
            ->select(['PollVote.*', 'PollOption.PollID as poll_id',
                'PollOption.DateInserted as created_at', // Total hack for approximate vote dates.
                'PollOption.DateUpdated as updated_at']);
        $this->import('poll_votes', $query, $map);
    }

    /** Requires addon `fof/reactions`. */
    public function reactions(): void
    {
        // Reaction Types
        $map = [
            'TagID' => 'id',
            'Name' => 'identifier',
            'Active' => 'enabled',
            'type=emoji', // @todo Setting type='emoji' is a kludge since it won't render Vanilla defaults that way.
        ];
        $filters = [
            'Active' => fn($val, $col, $row) => $val ?? 1,
        ];
        $query = $this->porterQB()->from('ReactionType')->select();
        $this->import('reactions', $query, $map, $filters);

        // Post Reactions
        $map = [
            'RecordID' => 'post_id',
            'UserID' => 'user_id',
            'TagID' => 'reaction_id',
            'DateInserted' => 'created_at',
        ];
        $query = $this->porterQB()->from('UserTag')->select()
            ->selectRaw('TIMESTAMP(DateInserted) as DateInserted')
            ->where('RecordType', '=', 'Comment')
            ->where('UserID', '>', 0);
        $this->import('post_reactions', $query, $map);

        // Get reactions for discussions (OPs).
        if ($this->useDiscussionBody()) {
            // Get highest CommentID.
            $lastCommentID = $this->porterQB()->from('Comment')->max('CommentID')->CommentID ?? 0;
            /* @see Target\Flarum::comments() —  replicate our math in the post split */
            $query = $this->porterQB()->from('UserTag')->select()
                ->selectRaw('(RecordID + ' . $lastCommentID . ') as RecordID')
                ->selectRaw('TIMESTAMP(DateInserted) as DateInserted')
                ->where('RecordType', '=', 'Discussion')
                ->where('UserID', '>', 0);
            $this->import('post_reactions', $query, $map);
        }
    }

    /**
     * Export PMs to fof/byobu format.
     *
     * Uses the `posts` & `discussions` tables, which would be a disaster if the plugin isn't enabled.
     * Therefore, this action is skipped if not being pointed at a pre-installed copy of Flarum with it.
     * If you want to force it to run, just create an empty `recipients` table (copying the fof/byobu structure).
     */
    protected function conversations(): void
    {
        // Verify target support (addon `fof/byobu`).
        if (!$this->hasOutputSchema('recipients')) {
            Log::comment("Skipping import: private messages (Enable fof/byobu or create its `recipients` table)");
            return;
        }

        // Messages — Discussions
        $MaxDiscussionID = $this->getMaxValue('id', 'discussions');
        Log::comment('Discussions offset for PMs is ' . $MaxDiscussionID);
        $this->setSchema('discussions', $this->getFlarumDiscussionSchema());
        $map = [
            'ConversationID' => ['id', 'slug'],
            'InsertUserID' => 'user_id',
            'DateInserted' => ['created_at', 'last_posted_at'], // @todo Orders old PMs by OP instead of last comment.
            'post_number_index=0',
            'is_sticky=0',
            'is_locked=0',
            'is_private=1',
            'votes=0', // Hedge against fof/gamification
            'hotness=0', // Hedge against fof/gamification
            'view_count=0',
            'best_answer_notified=1', // fof/best-answer
            'Subject' => 'title',
        ];
        $filters = [
            'ConversationID' => function ($val, $col, $row) use ($MaxDiscussionID) {
                return $val + $MaxDiscussionID;
            },
            'Subject' => fn($val, $col, $row) => $val ?? // Use generic numbered title if no Subject line.
                "Private discussion " . ($row['ConversationID'] + $MaxDiscussionID),
        ];
        $query = $this->porterQB()->from('Conversation')->select();
        $this->import('discussions', $query, $map, $filters);

        // Messages — Comments
        $MaxCommentID = $this->messagePostOffset = $this->getMaxValue('id', 'posts');
        Log::comment('Posts offset for PMs is ' . $MaxCommentID);
        $map = [
            'MessageID' => 'id',
            'ConversationID' => 'discussion_id',
            'Body' => 'content',
            'InsertUserID' => 'user_id',
            'DateInserted' => 'created_at',
            'is_private=1',
            'type=comment'
        ];
        $filters = [
            'Body' => \Porter\Filter\FlarumBody::class,
            'MessageID' => function ($val, $col, $row) use ($MaxCommentID) {
                return $val + $MaxCommentID;
            },
            'ConversationID' => function ($val, $col, $row) use ($MaxDiscussionID) {
                return $val + $MaxDiscussionID;
            },
        ];
        $query = $this->porterQB()->from('ConversationMessage')->select();
        $this->import('posts', $query, $map, $filters);

        // Recipients
        $structure = [
            'discussion_id' => 'int',
            'user_id' => 'int',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
        $this->setSchema('recipients', $structure);
        $map = [
            'ConversationID' => 'discussion_id',
            'UserID' => 'user_id',
            'DateConversationUpdated' => 'updated_at',
        ];
        $filters = [
            'ConversationID' => function ($val, $col, $row) use ($MaxDiscussionID) {
                return $val + $MaxDiscussionID;
            },
        ];
        $query = $this->porterQB()->from('UserConversation')->select();
        $this->import('recipients', $query, $map, $filters); // @todo
    }

    /** Use Media.Path to set Media.TargetFullPath. */
    protected function mapAttachments(string $fileTarget): int
    {
        $rows = 0;
        $attachments = $this->porterQB()->from('Media')->select(['MediaID'])
            // Reuse the filename in `Path` (not `Name`) in case it's been made guaranteed-unique.
            ->selectRaw("concat('{$fileTarget}/', Path) as TargetFullPath")
            // Assume we want the final Path if we got this far, so fix it.
            ->whereNotNull("Path")->get();
        foreach ($attachments as $attachment) {
            $rows += $this->dbOutput()->affectingStatement("update `PORT_Media`
                set TargetFullPath = " . $this->dbOutput()->escape($attachment->TargetFullPath) . "
                where MediaID = {$attachment->MediaID}");  // @todo index needed?
        }
        return $rows;
    }

    /** Use User.Photo to set Media.TargetAvatarFullPath. */
    protected function mapAvatars(string $fileTarget): int
    {
        $rows = 0;
        $avatars = $this->porterQB()->from('User')->select(['UserID'])
            ->selectRaw("concat('{$fileTarget}', Photo) as TargetAvatarFullPath")
            // Local-file Photo should begin with a slash.
            ->whereNotNull("SourceAvatarFullPath")->get(); // 'Photo' could be a URL otherwise.
        foreach ($avatars as $avatar) {
            $rows += $this->dbOutput()->affectingStatement("update `PORT_User`
                    set TargetAvatarFullPath = " . $this->dbOutput()->escape($avatar->TargetAvatarFullPath) . "
                    where UserID = {$avatar->UserID}"); // @todo index needed?
        }
        return $rows;
    }
}
