<?php

/**
 * NodeBB v4 target.
 *
 * NodeBB runs on MongoDB, so this target writes documents via `Storage\Mongo` rather than the
 * row-per-table `import()` path. Each record fans out to a `_key` hash plus the sorted sets &
 * counters NodeBB reads from. Derived data (counts, teasers, etc.) is finalized in the Postscript.
 *
 * @see https://docs.nodebb.org/
 */

namespace Porter\Target;

use Porter\Formatter;
use Porter\Log;
use Porter\Storage\Mongo;
use Porter\Target;

class NodeBb extends Target
{
    public const array SUPPORTED = [
        'name' => 'NodeBB 4.x',
        'defaultTablePrefix' => '',
        'avatarPath' => 'public/uploads/profile',
        'attachmentPath' => 'public/uploads/files',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Roles' => 1,
            'Avatars' => 1,
            'Attachments' => 1,
            'Polls' => 0,
            'PrivateMessages' => 0,
            'Signatures' => 0,
            'Bookmarks' => 0,
            'Badges' => 0,
            'Tags' => 0,
            'Reactions' => 0,
        ]
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false, // NodeBB's first post (mainPid) carries the topic body.
        'fileTransferSupport' => true,
    ];

    /** @var string Web path NodeBB serves the `public/uploads` folder from. */
    protected const string UPLOADS_WEB_PATH = '/assets/uploads';

    /** @var string Avatar folder, relative to the uploads root. */
    protected const string AVATAR_PATH = '/profile';

    /** @var string Attachment folder, relative to the uploads root. NodeBB keys uploads on this path. */
    protected const string ATTACHMENT_PATH = '/files';

    /** @var string The collection NodeBB keeps all hashes, sets and sorted sets in. */
    public const string OBJECTS = 'objects';

    /** @var int Rows read per query in the large loops. @see self::comments() */
    protected const int CHUNK_SIZE = 25000;

    /** @var string Group every NodeBB user belongs to; category privileges are granted to it. */
    protected const string GROUP_REGISTERED = 'registered-users';

    /** @var array Privileges NodeBB grants members on a new category. @see NodeBB `categories/create.js` */
    protected const array PRIVILEGES_MEMBER = [
        'groups:find', 'groups:read', 'groups:topics:read', 'groups:topics:create',
        'groups:topics:reply', 'groups:topics:crosspost', 'groups:topics:tag',
        'groups:posts:edit', 'groups:posts:history', 'groups:posts:delete',
        'groups:posts:upvote', 'groups:posts:downvote', 'groups:topics:delete',
    ];

    /** @var array Privileges NodeBB adds for moderators on top of the member set. */
    protected const array PRIVILEGES_MOD = [
        'groups:topics:schedule', 'groups:posts:view_deleted', 'groups:purge',
    ];

    /** @var array Privileges NodeBB grants unauthenticated visitors. */
    protected const array PRIVILEGES_GUEST = ['groups:find', 'groups:read', 'groups:topics:read'];

    /** @var array Which groups receive which privilege set on a migrated category. */
    protected const array PRIVILEGE_GRANTS = [
        'member' => ['registered-users', 'fediverse'],
        'mod' => ['administrators', 'Global Moderators'],
        'guest' => ['guests', 'spiders'],
    ];

    /** @var array NodeBB's own groups, which a source role must never overwrite. @see NodeBB `groups/index.js` */
    protected const array SYSTEM_GROUPS = [
        'registered-users',
        'verified-users',
        'unverified-users',
        'banned-users',
        'administrators',
        'Global Moderators',
    ];

    /** @var int|null Offset added to comment IDs so reply pids never collide with first-post pids. */
    protected ?int $pidOffset = null;

    /** @var array|null DiscussionID => pid of its first post. @see self::mainPids() */
    protected ?array $mainPids = null;

    /** @var array|null pid => list of its attachments. @see self::attachmentsByPid() */
    protected ?array $attachmentsByPid = null;

    /** @var array|null ID space already used by the target install. @see self::setup() */
    protected ?array $offsets = null;

    /** @var array Migrated members per group name, for the memberCount NodeBB reads. */
    protected array $memberCounts = [];

    /**
     * Read the ID space the target NodeBB has already used, so migrated records append past it.
     *
     * The `objects` collection holds NodeBB's config, system groups, `cid:*:privileges:*` records
     * and admin account alongside its content. Dropping it destroys the install, so records append to it
     * instead. Every migrated ID therefore starts above what the installer already assigned.
     */
    protected function setup(): void
    {
        $this->indexPorterTables();

        $global = $this->getObject('global');
        if ($global === null) {
            Log::comment('WARNING! No NodeBB install found at the output connection. Install NodeBB '
                . '& complete its setup BEFORE migrating, or the result will have no config or admin.');
        }
        // These hold the LAST id NodeBB assigned, not the next free one: it assigns from the
        // return of `incrObjectField`, so the counter and the highest live id are equal.
        $this->offsets = [
            'uid' => max(0, (int)($global['nextUid'] ?? 0)),
            'cid' => max(0, (int)($global['nextCid'] ?? 0)),
            'tid' => max(0, (int)($global['nextTid'] ?? 0)),
            'pid' => max(0, (int)($global['nextPid'] ?? 0)),
        ];
    }

    /**
     * Index the `PORT_` columns read by the chunked loops.
     *
     * The intermediary has no indexes; other targets stream it once so don't need them. Chunked
     * reads without an index are a full scan + filesort each time. @see self::comments()
     */
    protected function indexPorterTables(): void
    {
        $db = $this->dbPorter();
        $schema = $db->getDatabaseName();
        foreach (['Comment' => 'CommentID', 'Discussion' => 'DiscussionID'] as $table => $column) {
            $name = 'porter_' . strtolower($column);
            $exists = $db->selectOne(
                'select 1 as found from information_schema.statistics
                    where table_schema = ? and table_name = ? and index_name = ?',
                [$schema, "PORT_$table", $name]
            );
            if ($exists) {
                continue;
            }
            Log::comment("Indexing PORT_$table.$column for chunked reads...");
            $db->statement("alter table `PORT_$table` add index `$name` (`$column`)");
        }
    }

    /**
     * Start of the ID space this migration may use for a given record type.
     *
     * @param string $type One of 'uid', 'cid', 'tid', 'pid'.
     * @return int
     */
    protected function offset(string $type): int
    {
        return $this->offsets[$type] ?? 0;
    }

    /**
     * Translate a source key into the target's ID space.
     *
     * User 0 is guest in both Vanilla and NodeBB, so authorless content stays uid 0 rather than
     * being offset onto a real account — offsetting it lands on the install's admin (uid 0 + the
     * offset), silently attributing every guest post to them.
     */
    protected function uid(int $userID): int
    {
        return ($userID > 0) ? $userID + $this->offset('uid') : 0;
    }

    protected function cid(int $categoryID): int
    {
        return $categoryID + $this->offset('cid');
    }

    protected function tid(int $discussionID): int
    {
        return $discussionID + $this->offset('tid');
    }

    protected function pid(int $commentID): int
    {
        return $commentID + $this->pidOffset();
    }

    /**
     * The pid of a discussion's first post.
     *
     * With the body mode on the OP is synthesized from `Discussion.Body`, taking its own pid from
     * the same space as the comments. With it off the OP is an existing comment.
     *
     * @param int $discussionID
     * @return int
     */
    protected function opPid(int $discussionID): int
    {
        if (!$this->getDiscussionBodyMode()) {
            $mainPid = $this->mainPids()[$discussionID] ?? 0;
            return $mainPid ? $this->pid($mainPid) : 0;
        }
        return $this->offset('pid') + $discussionID;
    }

    /**
     * Enforce data constraints required by NodeBB.
     */
    public function validate(): void
    {
        $this->uniqueUserNames();
        $this->uniqueUserEmails();
        $this->topicsHaveFirstPost();
    }

    /**
     * Every NodeBB topic needs a `mainPid`. Report any that can't be pointed at a post.
     *
     * Only possible with 'Use Discussion Body' off, where the OP has to be found among the comments.
     */
    protected function topicsHaveFirstPost(): void
    {
        if ($this->getDiscussionBodyMode()) {
            return; // The body is on the discussion record, so every topic has one.
        }
        // Joined rather than `whereNotIn(...)`: that binds a placeholder per discussion and MySQL
        // caps a statement at 65535 of them, which a real forum passes easily.
        $orphans = $this->porterQB()->from('Discussion')
            ->leftJoin('Comment', 'Comment.DiscussionID', '=', 'Discussion.DiscussionID')
            ->whereNull('Comment.CommentID')
            ->count();
        if ($orphans) {
            Log::comment("DATA LOSS! Discussions with no posts, so no NodeBB mainPid: $orphans");
        }
    }

    /**
     * Write users as `user:{uid}` hashes, plus the lookup and listing sorted sets.
     */
    protected function users(): void
    {
        $rows = $this->porterQB()->from('User')
            ->select([
                'UserID', 'Name', 'Email', 'Password', 'Banned',
                'DateInserted', 'DateLastActive', 'TargetAvatarFullPath',
            ])
            ->cursor();
        // NodeBB enforces a unique {_key,value} index, so each lookup value may appear once (first uid wins).
        $seenName = $seenSlug = $seenEmail = [];
        $now = $this->now();
        foreach ($rows as $user) {
            $uid = $this->uid((int)$user->UserID);
            // NodeBB requires unique usernames, and every deleted user shares one. Renaming them
            // is what uniqueUserNames() assumes has happened when it allowlists those names.
            $name = (string)fixDuplicateDeletedNames((string)$user->Name, 'Name', (array)$user);
            $slug = $this->slugify($name, (string)$uid);
            $email = strtolower((string)$user->Email);
            $joined = $this->toMillis($user->DateInserted);
            // `filemap()` runs first, so read the destination it set rather than rebuilding it.
            $picture = empty($user->TargetAvatarFullPath)
                ? ''
                : self::UPLOADS_WEB_PATH . self::AVATAR_PATH . '/' . basename((string)$user->TargetAvatarFullPath);

            $this->setObject("user:$uid", [
                'uid' => $uid,
                'username' => $name,
                'userslug' => $slug,
                'email' => (string)$user->Email,
                'joindate' => $joined,
                'lastonline' => $this->toMillis($user->DateLastActive),
                'postcount' => 0,
                'topiccount' => 0,
                'reputation' => 0,
                'banned' => (int)$user->Banned,
                'password' => (string)$user->Password, // @see README on password hash compatibility.
                'picture' => $picture,
                'uploadedpicture' => $picture,
            ]);

            // Username and email lookups to uid (deduped: NodeBB requires each value be unique).
            if ($name !== '' && !isset($seenName[$name])) {
                $this->sortedSetAdd('username:uid', $name, $uid);
                $this->sortedSetAdd('username:sorted', strtolower($name) . ':' . $uid, 0); // Search.
                $seenName[$name] = true;
            }
            if ($slug !== '' && !isset($seenSlug[$slug])) {
                $this->sortedSetAdd('userslug:uid', $slug, $uid);
                $seenSlug[$slug] = true;
            }
            if ($email !== '' && !isset($seenEmail[$email])) {
                $this->sortedSetAdd('email:uid', $email, $uid);
                $seenEmail[$email] = true;
            }

            // User listings.
            $this->sortedSetAdd('users:joindate', $uid, $joined);
            $this->sortedSetAdd('users:postcount', $uid, 0);
            $this->sortedSetAdd('users:reputation', $uid, 0);

            // Every NodeBB user is in `registered-users`; category privileges are granted to it,
            // so a user outside it cannot read or post anywhere. @see NodeBB `user/create.js`
            $this->sortedSetAdd('group:' . self::GROUP_REGISTERED . ':members', $uid, $now);
            $this->memberCounts[self::GROUP_REGISTERED] =
                ($this->memberCounts[self::GROUP_REGISTERED] ?? 0) + 1;
        }
    }

    /**
     * Write roles as NodeBB groups, and user-role assignments as group membership.
     */
    protected function roles(): void
    {
        // Verify support.
        if (!$this->hasPortSchema('UserRole')) {
            Log::comment('Skipping import: Roles (Source lacks support)');
            return;
        }

        // Delete orphaned user role associations (deleted users).
        $this->pruneOrphanedRecords('UserRole', 'UserID', 'User', 'UserID');

        $now = $this->now();

        $roles = $this->porterQB()->from('Role')->select(['RoleID', 'Name', 'Description'])->cursor();
        // Groups are keyed by name, and NodeBB indexes `{_key,value}` unique: a duplicate here makes
        // its index build fail on boot, so the whole forum stops working. Names are also a shared
        // namespace with NodeBB's own groups, which must not be redefined.
        $seenGroup = array_fill_keys(array_map('strtolower', self::SYSTEM_GROUPS), true);
        $groupSlugs = [];
        foreach ($roles as $role) {
            $name = (string)$role->Name;
            if ($name === '' || isset($seenGroup[strtolower($name)])) {
                Log::comment("DATA LOSS! Role skipped for duplicate or reserved group name: $name");
                continue;
            }
            $seenGroup[strtolower($name)] = true;

            $this->setObject("group:$name", [
                'name' => $name,
                'slug' => $this->slugify($name, 'group'),
                'description' => (string)$role->Description,
                'createtime' => $now,
                'memberCount' => 0,
                'hidden' => 0,
                'system' => 0,
                'private' => 1,
                'userTitle' => $name,
                'userTitleEnabled' => 0,
                'disableJoinRequests' => 1,
                'disableLeave' => 0,
            ]);
            // Resolves a group page by its slug. @see NodeBB `groups/create.js`
            $groupSlugs[$this->slugify($name, 'group')] = $name;
            $this->sortedSetAdd('groups:createtime', $name, $now);
            $this->sortedSetAdd('groups:visible:createtime', $name, $now);
            $this->sortedSetAdd('groups:visible:name', strtolower($name) . ':' . $name, 0);
            // Counted in groupCounts(), which owns the `groups:visible:memberCount` entry: a
            // second write of the same {_key,value} pair breaks NodeBB's unique index.
            $this->memberCounts[$name] = 0;
        }

        // Memberships (join to resolve the group name from the role ID).
        $members = $this->porterQB()->from('UserRole')
            ->leftJoin('Role', 'UserRole.RoleID', '=', 'Role.RoleID')
            ->select(['UserRole.UserID', 'Role.Name'])
            ->cursor();
        $seenMember = [];
        foreach ($members as $member) {
            $name = (string)$member->Name;
            $uid = $this->uid((int)$member->UserID);
            // Skip orphaned roles, roles not created here, and duplicate memberships (unique {_key,value}).
            if ($name === '' || !isset($groupSlugs[$this->slugify($name, 'group')])) {
                continue;
            }
            if (isset($seenMember["$name:$uid"])) {
                continue;
            }
            $seenMember["$name:$uid"] = true;
            $this->sortedSetAdd("group:$name:members", $uid, $now);
            $this->memberCounts[$name] = ($this->memberCounts[$name] ?? 0) + 1;
        }

        $this->setObjectFields('groupslug:groupname', $groupSlugs);
    }

    /**
     * Write categories as `category:{cid}` hashes, plus the listing sorted sets.
     */
    protected function categories(): void
    {
        $rows = $this->porterQB()->from('Category')
            ->select(['CategoryID', 'ParentCategoryID', 'Name', 'UrlCode', 'Description', 'Sort'])
            ->where('CategoryID', '!=', -1) // Ignore Vanilla's root category.
            ->cursor();
        foreach ($rows as $category) {
            $cid = $this->cid((int)$category->CategoryID);
            // Vanilla's root parent is -1 (or null); NodeBB lists top-level categories under root cid 0.
            $parentID = (int)$category->ParentCategoryID;
            $parentCid = ($parentID > 0) ? $this->cid($parentID) : 0;
            $order = (int)$category->Sort;
            $name = (string)$category->Name;

            $this->setObject("category:$cid", [
                'cid' => $cid,
                'name' => $name,
                'slug' => "$cid/" . $this->slugify($name, (string)$cid),
                'description' => (string)$category->Description,
                'parentCid' => $parentCid,
                'order' => $order,
                'topic_count' => 0,
                'post_count' => 0,
                'disabled' => 0,
            ]);
            $this->sortedSetAdd('categories:cid', $cid, $order);
            $this->sortedSetAdd("cid:$parentCid:children", $cid, $order);
            $this->sortedSetAdd('categories:name', strtolower($name) . ':' . $cid, 0); // Admin search.
            $this->grantCategoryPrivileges($cid);
        }
    }

    /**
     * Grant a migrated category the privileges NodeBB gives one it created itself.
     *
     * A privilege is a hidden system group named `cid:{cid}:privileges:{name}` whose members are
     * the groups holding it. Without them NodeBB shows the category to nobody, admin included, so
     * a migration that skips this produces a forum with invisible content.
     * @see NodeBB `categories/create.js` and `privileges/categories.js`
     *
     * @param int $cid
     */
    protected function grantCategoryPrivileges(int $cid): void
    {
        $now = $this->now();
        // Members hold their own set; moderators hold that plus more; guests a read-only subset.
        $grants = array_fill_keys(self::PRIVILEGES_MOD, []);
        foreach (self::PRIVILEGES_MEMBER as $privilege) {
            $grants[$privilege] = self::PRIVILEGE_GRANTS['member'];
        }
        foreach (array_keys($grants) as $privilege) {
            $grants[$privilege] = array_merge($grants[$privilege], self::PRIVILEGE_GRANTS['mod']);
        }
        foreach (self::PRIVILEGES_GUEST as $privilege) {
            $grants[$privilege] = array_merge($grants[$privilege], self::PRIVILEGE_GRANTS['guest']);
        }

        foreach ($grants as $privilege => $members) {
            $name = "cid:$cid:privileges:$privilege";
            $this->setObject("group:$name", [
                'name' => $name,
                'slug' => $this->slugify($name, "cid-$cid"),
                'description' => '',
                'createtime' => $now,
                'memberCount' => count($members),
                'hidden' => 1,
                'system' => 1,
                'private' => 1,
                'userTitle' => $name,
                'userTitleEnabled' => 0,
                'disableJoinRequests' => 0,
                'disableLeave' => 0,
            ]);
            foreach ($members as $member) {
                $this->sortedSetAdd("group:$name:members", $member, $now);
            }
        }
    }

    /**
     * Write discussions as `topic:{tid}` hashes, their first post (mainPid), and the listing sorted sets.
     *
     * With 'Use Discussion Body' on, the OP is `Discussion.Body`, synthesized as a post.
     * With it off the OP is already a `Comment` row, so just point `mainPid` at it and let
     * comments() write it.
     * @see Package::getDiscussionBodyMode()
     */
    protected function discussions(): void
    {
        $useBody = $this->getDiscussionBodyMode();
        // Resolve before the first chunk: both are memoized & used inside the loop.
        $mainPids = $useBody ? [] : $this->mainPids();
        $this->attachmentsByPid(); // Primed here; writePost() appends the links.
        $this->porterQB()->from('Discussion')
            ->select([
                'DiscussionID', 'CategoryID', 'InsertUserID', 'Name', 'Body', 'Format',
                'CountViews', 'Closed', 'Announce', 'DateInserted', 'DateLastComment',
            ])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($useBody, $mainPids) {
                $this->writeTopics($rows, $useBody, $mainPids);
            }, 'DiscussionID');
    }

    /**
     * Write one chunk of discussions.
     *
     * @param iterable $rows
     * @param bool $useBody Whether the OP arrives on the discussion record.
     * @param array $mainPids DiscussionID => CommentID of its first post.
     */
    protected function writeTopics(iterable $rows, bool $useBody, array $mainPids): void
    {
        foreach ($rows as $topic) {
            $discussionID = (int)$topic->DiscussionID;
            $tid = $this->tid($discussionID);
            $cid = $this->cid((int)$topic->CategoryID);
            $uid = $this->uid((int)$topic->InsertUserID);
            $timestamp = $this->toMillis($topic->DateInserted);
            $lastposttime = $this->toMillis($topic->DateLastComment) ?: $timestamp;
            $views = (int)$topic->CountViews;
            $title = (string)$topic->Name;
            $mainPid = $this->opPid($discussionID);

            $this->setObject("topic:$tid", [
                'tid' => $tid,
                'uid' => $uid,
                'cid' => $cid,
                'title' => $title,
                'slug' => "$tid/" . $this->slugify($title, (string)$tid),
                'mainPid' => $mainPid,
                'teaserPid' => $mainPid, // Category rows read this; no replies are teased yet.
                'timestamp' => $timestamp,
                'lastposttime' => $lastposttime,
                'postcount' => 1,
                'viewcount' => $views,
                'postercount' => 1,
                'votes' => 0,
                'locked' => (int)$topic->Closed,
                'pinned' => (int)$topic->Announce,
                'deleted' => 0,
            ]);
            $this->sortedSetAdd('topics:tid', $tid, $timestamp);
            $this->sortedSetAdd('topics:recent', $tid, $lastposttime);
            $this->sortedSetAdd('topics:views', $tid, $views);
            $this->sortedSetAdd('topics:posts', $tid, 0);
            $this->sortedSetAdd('topics:votes', $tid, 0);
            $this->sortedSetAdd("cid:$cid:tids", $tid, $timestamp);
            $this->sortedSetAdd("cid:$cid:tids:create", $tid, $timestamp);
            $this->sortedSetAdd("cid:$cid:tids:lastposttime", $tid, $lastposttime);
            $this->sortedSetAdd("cid:$cid:tids:views", $tid, $views);
            $this->sortedSetAdd("cid:$cid:tids:posts", $tid, 0);
            $this->sortedSetAdd("cid:$cid:tids:votes", $tid, 0);
            $this->sortedSetAdd("cid:$cid:uid:$uid:tids", $tid, $timestamp);
            $this->sortedSetAdd("uid:$uid:topics", $tid, $timestamp);
            $this->sortedSetAdd("tid:$tid:posters", $uid, 1);

            if ($useBody) {
                // The first post carries the topic body.
                $content = $this->formatContent($topic->Format, $topic->Body);
                $this->writePost($mainPid, $tid, $cid, $uid, $content, $timestamp, false);
            }
        }
    }

    /**
     * Write comments as `post:{pid}` hashes, plus the listing sorted sets.
     */
    protected function comments(): void
    {
        // Resolve before opening the cursor: the porter connection is unbuffered,
        // so no other query may run while a cursor is active.
        $this->pidOffset();
        $useBody = $this->getDiscussionBodyMode();
        $mainPids = $useBody ? [] : $this->mainPids();
        $this->attachmentsByPid(); // Primed here; writePost() appends the links.
        // Chunk rather than cursor: a single cursor over every comment grows the heap past the
        // memory limit. `Storage::store()` favors a cursor, but writes one row where this fans a
        // comment out to several documents.
        $this->porterQB()->from('Comment')
            ->leftJoin('Discussion', 'Comment.DiscussionID', '=', 'Discussion.DiscussionID')
            ->select([
                'Comment.CommentID', 'Comment.DiscussionID', 'Comment.InsertUserID',
                'Comment.Body', 'Comment.Format', 'Comment.DateInserted', 'Discussion.CategoryID',
            ])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($useBody, $mainPids) {
                $this->writeComments($rows, $useBody, $mainPids);
            }, 'Comment.CommentID', 'CommentID');
    }

    /**
     * Write one chunk of comments.
     *
     * @param iterable $rows
     * @param bool $useBody Whether the OP arrived on the discussion record.
     * @param array $mainPids DiscussionID => CommentID of its first post.
     */
    protected function writeComments(iterable $rows, bool $useBody, array $mainPids): void
    {
        foreach ($rows as $comment) {
            $commentID = (int)$comment->CommentID;
            $discussionID = (int)$comment->DiscussionID;
            $pid = $this->pid($commentID);
            $tid = $this->tid($discussionID);
            $cid = $this->cid((int)$comment->CategoryID);
            $uid = $this->uid((int)$comment->InsertUserID);
            $timestamp = $this->toMillis($comment->DateInserted);
            // With the body mode off one of these comments IS the OP, so it must not list as a reply.
            $isReply = $useBody || $commentID !== ($mainPids[$discussionID] ?? 0);
            $content = $this->formatContent($comment->Format, $comment->Body);

            $this->writePost($pid, $tid, $cid, $uid, $content, $timestamp, $isReply);
        }
    }

    /**
     * A post body as NodeBB stores it.
     *
     * Markdown stays raw so NodeBB's own renderer handles it (mentions, emoji); other formats are
     * rendered to HTML, which NodeBB also accepts.
     *
     * @param ?string $format
     * @param ?string $body
     * @return string
     */
    protected function formatContent(?string $format, ?string $body): string
    {
        if (strtolower((string)$format) === 'markdown') {
            return (string)$body;
        }
        return Formatter::instance()->toHtml($format, $body);
    }

    /**
     * Map of DiscussionID => pid of its first post, for when the OP is a `Comment` row.
     *
     * `Discussion.FirstCommentID` is only populated by a handful of sources, so derive it from the
     * lowest CommentID per discussion instead. Memoized because discussions() and comments() must
     * agree on which post is the OP.
     *
     * @todo In-memory map sized to the discussion count.
     * @return array
     */
    protected function mainPids(): array
    {
        if ($this->mainPids === null) {
            $this->mainPids = [];
            $rows = $this->porterQB()->from('Comment')
                ->select('DiscussionID')
                ->selectRaw('min(CommentID) as FirstPid')
                ->groupBy('DiscussionID')
                ->get();
            foreach ($rows as $row) {
                $this->mainPids[(int)$row->DiscussionID] = (int)$row->FirstPid;
            }
        }
        return $this->mainPids;
    }

    /**
     * Write a single `post:{pid}` hash and its listing sorted sets.
     *
     * @param int $pid
     * @param int $tid
     * @param int $cid
     * @param int $uid
     * @param string $content
     * @param int $timestamp Milliseconds since epoch.
     * @param bool $isReply Replies join `tid:{tid}:posts`; the first post (mainPid) does not.
     */
    protected function writePost(
        int $pid,
        int $tid,
        int $cid,
        int $uid,
        string $content,
        int $timestamp,
        bool $isReply
    ): void {
        $this->setObject("post:$pid", [
            'pid' => $pid,
            'tid' => $tid,
            'uid' => $uid,
            'content' => $content . $this->attachmentLinks($pid),
            'timestamp' => $timestamp,
            'deleted' => 0,
        ]);
        $this->sortedSetAdd('posts:pid', $pid, $timestamp);
        $this->sortedSetAdd('posts:votes', $pid, 0);
        $this->sortedSetAdd("uid:$uid:posts", $pid, $timestamp);
        $this->sortedSetAdd("cid:$cid:pids", $pid, $timestamp);
        $this->sortedSetAdd("cid:$cid:uid:$uid:pids", $pid, $timestamp);
        if ($isReply) {
            $this->sortedSetAdd("tid:$tid:posts", $pid, $timestamp);
            $this->sortedSetAdd("tid:$tid:posts:votes", $pid, 0);
        }
    }

    /**
     * Write attachments as `upload:{md5}` hashes, plus the sorted sets tying them to their post
 * and uploader.
     *
     * NodeBB has no attachment records of its own: an upload is the file on disk, a hash keyed on
     * its path, and a link in the post content (which writePost() appends). We reproduce what
     * `Posts.uploads.associate()` and `User.associateUpload()` write.
     *
     * @see https://github.com/NodeBB/NodeBB/blob/master/src/posts/uploads.js
     */
    protected function attachments(): void
    {
        if (!$this->hasPortSchema('Media')) {
            Log::comment('Skipping import: Attachments (Source lacks support)');
            return;
        }
        $postUploads = [];
        foreach ($this->attachmentsByPid() as $pid => $attachments) {
            $paths = [];
            foreach ($attachments as $attachment) {
                $key = 'upload:' . md5($attachment['path']);
                $paths[] = $attachment['path'];

                // Image dimensions are all NodeBB stores here. @see `Posts.uploads.saveSize()`
                $upload = ['uid' => $attachment['uid']];
                if ($attachment['width'] && $attachment['height']) {
                    $upload['width'] = $attachment['width'];
                    $upload['height'] = $attachment['height'];
                }
                $this->setObject($key, $upload);
                $this->sortedSetAdd("$key:pids", $pid, $attachment['timestamp']);
                $this->sortedSetAdd(
                    "uid:{$attachment['uid']}:uploads",
                    $attachment['path'],
                    $attachment['timestamp']
                );
            }
            // NodeBB stores this as a JSON string, not an array. @see `Posts.uploads.associate()`
            $postUploads["post:$pid"] = ['uploads' => json_encode($paths)];
        }
        if (!empty($postUploads)) {
            $this->setObjectFieldsBulk($postUploads);
        }
    }

    /**
     * Map of pid => list of its attachments.
     *
     * Keyed on the pid so writePost() can append links as it streams. Built from `Media.Path`, the
     * same value the file transfer copies to, so the records hold whether or not files are moved.
     *
     * @todo In-memory map sized to the attachment count.
     * @return array
     */
    protected function attachmentsByPid(): array
    {
        if ($this->attachmentsByPid !== null) {
            return $this->attachmentsByPid;
        }
        $this->attachmentsByPid = [];
        if (!$this->hasPortSchema('Media')) {
            return $this->attachmentsByPid; // Reported by attachments().
        }

        $rows = $this->porterQB()->from('Media')
            ->select([
                'Name', 'Path', 'Type', 'ForeignID', 'ForeignTable',
                'InsertUserID', 'DateInserted', 'ImageWidth', 'ImageHeight',
            ])
            ->whereNotNull('Path')
            ->get();
        foreach ($rows as $media) {
            $pid = $this->resolveAttachmentPid((string)$media->ForeignTable, (int)$media->ForeignID);
            if (!$pid) {
                continue; // Attached to something this target doesn't migrate (e.g. a PM or an embed).
            }
            $this->attachmentsByPid[$pid][] = [
                'path' => self::ATTACHMENT_PATH . '/' . ltrim((string)$media->Path, '/'),
                'name' => (string)$media->Name,
                'type' => (string)$media->Type,
                'uid' => $this->uid((int)$media->InsertUserID),
                'timestamp' => $this->toMillis($media->DateInserted),
                'width' => (int)$media->ImageWidth,
                'height' => (int)$media->ImageHeight,
            ];
        }

        return $this->attachmentsByPid;
    }

    /**
     * Find the pid an attachment belongs to, or 0 if its parent wasn't migrated.
     *
     * Sources disagree on the case of `Media.ForeignTable` and also use it for records with no post
     * (e.g. 'embed', 'message'), so match case-insensitively and ignore the rest.
     *
     * @param string $foreignTable
     * @param int $foreignID
     * @return int
     */
    protected function resolveAttachmentPid(string $foreignTable, int $foreignID): int
    {
        return match (strtolower($foreignTable)) {
            'discussion' => $this->opPid($foreignID),
            'comment' => $this->pid($foreignID),
            default => 0,
        };
    }

    /**
     * Render a post's attachments as content, since that is the only place NodeBB shows them.
     *
     * HTML rather than Markdown because it survives either renderer.
     *
     * @param int $pid
     * @return string
     */
    protected function attachmentLinks(int $pid): string
    {
        $links = '';
        foreach ($this->attachmentsByPid()[$pid] ?? [] as $attachment) {
            $url = self::UPLOADS_WEB_PATH . $attachment['path'];
            $name = htmlspecialchars($attachment['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $links .= str_starts_with($attachment['type'], 'image/')
                ? '<p><img src="' . $url . '" alt="' . $name . '" /></p>'
                : '<p><a href="' . $url . '">' . $name . '</a></p>';
        }
        return $links;
    }

    /**
     * Aggregates NodeBB reads, totaled once everything else is written.
     *
     * Counts come from the relational `PORT_` data so the document engine stays write-only.
     */
    protected function cleanup(): void
    {
        $this->topicCounts();
        $this->categoryCounts();
        $this->userCounts();
        $this->groupCounts();
        $this->globals();

        // Index `_key` once the bulk load is done so the updates above don't scan the collection.
        $this->mongo()->flush();
        $this->indexObjects();
    }

    /**
     * How many posts each discussion contributes on top of its `PORT_Comment` rows.
     *
     * With the body mode on a first post was synthesized from `Discussion.Body`, so every topic is
     * worth one more post than it has comments. With it off the OP is already a comment and counting
     * it again would inflate every total.
     *
     * @return int
     */
    protected function firstPostCount(): int
    {
        return $this->getDiscussionBodyMode() ? 1 : 0;
    }

    /**
     * Set `topic.postcount` to its replies plus the first post.
     */
    protected function topicCounts(): void
    {
        $rows = $this->porterQB()->from('Comment')
            ->select('DiscussionID')
            ->selectRaw('count(*) as found_count')
            ->groupBy('DiscussionID')
            ->get();
        $firstPost = $this->firstPostCount();
        $updates = [];
        foreach ($rows as $row) {
            $tid = $this->tid((int)$row->DiscussionID);
            $updates["topic:$tid"] = ['postcount' => (int)$row->found_count + $firstPost];
        }
        $this->setObjectFieldsBulk($updates);
    }

    /**
     * Set `category.topic_count` and `category.post_count` (first posts + replies).
     */
    protected function categoryCounts(): void
    {
        $topicCounts = [];
        // Join Category so a discussion pointing at an unmigrated category cannot land its
        // count on an install category that happens to occupy the same offset ID.
        $topicRows = $this->porterQB()->from('Discussion')
            ->join('Category', 'Category.CategoryID', '=', 'Discussion.CategoryID')
            ->select('Discussion.CategoryID')
            ->selectRaw('count(*) as found_count')
            ->where('Category.CategoryID', '!=', -1)
            ->groupBy('Discussion.CategoryID')
            ->get();
        foreach ($topicRows as $row) {
            $topicCounts[(int)$row->CategoryID] = (int)$row->found_count;
        }

        $commentCounts = [];
        $commentRows = $this->porterQB()->from('Comment')
            ->join('Discussion', 'Comment.DiscussionID', '=', 'Discussion.DiscussionID')
            ->join('Category', 'Category.CategoryID', '=', 'Discussion.CategoryID')
            ->select('Discussion.CategoryID')
            ->selectRaw('count(*) as found_count')
            ->where('Category.CategoryID', '!=', -1)
            ->groupBy('Discussion.CategoryID')
            ->get();
        foreach ($commentRows as $row) {
            $commentCounts[(int)$row->CategoryID] = (int)$row->found_count;
        }

        $firstPost = $this->firstPostCount();
        $updates = [];
        foreach ($topicCounts as $categoryID => $topics) {
            $cid = $this->cid($categoryID);
            $updates["category:$cid"] = [
                'topic_count' => $topics,
                'post_count' => ($topics * $firstPost) + ($commentCounts[$categoryID] ?? 0),
            ];
        }
        $this->setObjectFieldsBulk($updates);
    }

    /**
     * Set `user.topiccount` and `user.postcount` (first posts + replies).
     */
    protected function userCounts(): void
    {
        $topicCounts = [];
        $topicRows = $this->porterQB()->from('Discussion')
            ->select('InsertUserID')
            ->selectRaw('count(*) as found_count')
            ->groupBy('InsertUserID')
            ->get();
        foreach ($topicRows as $row) {
            $topicCounts[(int)$row->InsertUserID] = (int)$row->found_count;
        }

        $commentCounts = [];
        $commentRows = $this->porterQB()->from('Comment')
            ->select('InsertUserID')
            ->selectRaw('count(*) as found_count')
            ->groupBy('InsertUserID')
            ->get();
        foreach ($commentRows as $row) {
            $commentCounts[(int)$row->InsertUserID] = (int)$row->found_count;
        }

        $firstPost = $this->firstPostCount();
        $userIDs = array_unique(array_merge(array_keys($topicCounts), array_keys($commentCounts)));
        $updates = [];
        foreach ($userIDs as $userID) {
            $uid = $this->uid($userID);
            $topics = $topicCounts[$userID] ?? 0;
            $updates["user:$uid"] = [
                'topiccount' => $topics,
                'postcount' => ($topics * $firstPost) + ($commentCounts[$userID] ?? 0),
            ];
        }
        $this->setObjectFieldsBulk($updates);
    }

    /**
     * Set `group.memberCount`, which NodeBB shows and sorts on but never recalculates.
     *
     * `registered-users` already had the install's own members, so add to what is there.
     */
    protected function groupCounts(): void
    {
        foreach ($this->memberCounts as $name => $migrated) {
            $existing = (int)($this->getObject("group:$name")['memberCount'] ?? 0);
            $total = ($name === self::GROUP_REGISTERED) ? $existing + $migrated : $migrated;
            $this->setObjectFields("group:$name", ['memberCount' => $total]);
            $this->sortedSetUpdate('groups:visible:memberCount', $name, $total);
        }
    }

    /**
     * Advance the `next*` counters and totals NodeBB reads on boot, past the migrated records.
     */
    protected function globals(): void
    {
        $topicCount = $this->porterQB()->from('Discussion')->count();
        $commentCount = $this->porterQB()->from('Comment')->count();
        $global = $this->getObject('global') ?? [];
        $posts = ($topicCount * $this->firstPostCount()) + $commentCount;

        $this->setObjectFields('global', [
            // Set to the highest id assigned; NodeBB increments before using it.
            'nextUid' => $this->uid((int)$this->porterQB()->from('User')->max('UserID')),
            'nextCid' => $this->cid((int)$this->porterQB()->from('Category')->max('CategoryID')),
            'nextTid' => $this->tid((int)$this->porterQB()->from('Discussion')->max('DiscussionID')),
            'nextPid' => $this->maxPid(),
            // Totals include whatever the target install already had.
            'userCount' => (int)($global['userCount'] ?? 0) + $this->porterQB()->from('User')->count(),
            'categoryCount' => (int)($global['categoryCount'] ?? 0)
                + $this->porterQB()->from('Category')->count(),
            'topicCount' => (int)($global['topicCount'] ?? 0) + $topicCount,
            'postCount' => (int)($global['postCount'] ?? 0) + $posts,
        ]);
    }

    /**
     * Highest pid this migration assigned, across both ways the OP can arrive.
     *
     * @return int
     */
    protected function maxPid(): int
    {
        $maxComment = (int)$this->porterQB()->from('Comment')->max('CommentID');
        $maxDiscussion = (int)$this->porterQB()->from('Discussion')->max('DiscussionID');

        return max(
            $maxComment ? $this->pid($maxComment) : 0,
            $this->getDiscussionBodyMode() ? $this->offset('pid') + $maxDiscussion : 0,
            $this->offset('pid')
        );
    }

    /**
     * Assign a new location for message file attachments.
     *
     * Format: {approot}/public/uploads/files/{Media.Path}
     * Reuses `Path` so the file lands where attachments() already pointed the upload records.
     * Unlike a SQL target the output store is MongoDB, so this updates `PORT_` on the porter
     * (SQL) connection.
     * @see self::filemap()
     * @see self::SUPPORTED [attachmentPath]
     */
    protected function mapAttachments(string $fileTarget): int
    {
        $rows = 0;
        $attachments = $this->porterQB()->from('Media')
            ->select(['MediaID'])
            ->selectRaw("concat('{$fileTarget}/', trim(leading '/' from Path)) as TargetFullPath")
            ->whereNotNull('Path')
            ->get();
        foreach ($attachments as $attachment) {
            $rows += $this->dbPorter()->affectingStatement("update `PORT_Media`
                set TargetFullPath = " . $this->dbPorter()->escape($attachment->TargetFullPath) . "
                where MediaID = {$attachment->MediaID}");
        }

        return $rows;
    }

    /**
     * Assign a new location for user photos / avatars.
     *
     * Format: {approot}/public/uploads/profile/{original filename}
     * Unlike a SQL target the output store is MongoDB, so this updates `PORT_` on the porter
     * (SQL) connection. users() then reads the mapped path back to set the NodeBB `picture`.
     * @see self::filemap()
     * @see self::SUPPORTED [avatarPath]
     */
    protected function mapAvatars(string $fileTarget): int
    {
        $rows = 0;
        $avatars = $this->porterQB()->from('User')
            ->select(['UserID'])
            ->selectRaw("concat('{$fileTarget}/', substring_index(Photo, '/', -1)) as TargetAvatarFullPath")
            ->whereNotNull('SourceAvatarFullPath')
            ->whereNotNull('Photo')
            ->get();
        foreach ($avatars as $avatar) {
            $rows += $this->dbPorter()->affectingStatement("update `PORT_User`
                set TargetAvatarFullPath = " . $this->dbPorter()->escape($avatar->TargetAvatarFullPath) . "
                where UserID = {$avatar->UserID}");
        }

        return $rows;
    }

    /**
     * The output storage, typed for the document primitives.
     *
     * @return Mongo
     */
    protected function mongo(): Mongo
    {
        assert($this->outputStorage instanceof Mongo);
        return $this->outputStorage;
    }

    /**
     * NodeBB models Redis in Mongo: every hash and sorted set is a document in one collection.
     * The methods below express that over the storage's generic primitives.
     * @see https://github.com/NodeBB/NodeBB/tree/master/src/database/mongo
     */

    /**
     * Write a hash object (e.g. `user:1`) as a single `{_key, ...fields}` document.
     *
     * @param string $key The object's `_key`.
     * @param array $fields Name => value.
     */
    protected function setObject(string $key, array $fields): void
    {
        $this->mongo()->insert(self::OBJECTS, array_merge(['_key' => $key], $fields));
    }

    /**
     * Read a hash object back, for the few cases that must not overwrite what NodeBB already has.
     *
     * @param string $key The object's `_key`.
     * @return array|null Null if no such object.
     */
    protected function getObject(string $key): ?array
    {
        return $this->mongo()->findOne(self::OBJECTS, ['_key' => $key]);
    }

    /**
     * Merge fields into one hash object, creating it if the install has no such record.
     *
     * @param string $key The object's `_key`.
     * @param array $fields Name => value.
     */
    protected function setObjectFields(string $key, array $fields): void
    {
        if (empty($fields)) {
            return;
        }
        $this->mongo()->updateOne(self::OBJECTS, ['_key' => $key], ['$set' => $fields], true);
    }

    /**
     * Set fields on many existing hash objects in one batched pass. Does not upsert.
     *
     * @param array $updates Map of `_key` => [field => value].
     */
    protected function setObjectFieldsBulk(array $updates): void
    {
        $operations = [];
        foreach ($updates as $key => $fields) {
            $operations[] = ['updateOne' => [['_key' => $key], ['$set' => $fields]]];
        }
        $this->mongo()->bulkWrite(self::OBJECTS, $operations);
    }

    /**
     * Add a member to a sorted set (e.g. `cid:1:tids`) as a `{_key, value, score}` document.
     *
     * @param string $key The sorted set's `_key`.
     * @param int|string $value The member.
     * @param int|float $score The sort score.
     */
    protected function sortedSetAdd(string $key, int|string $value, int|float $score): void
    {
        $this->mongo()->insert(self::OBJECTS, ['_key' => $key, 'value' => (string)$value, 'score' => $score]);
    }

    /**
     * Add or re-score one sorted set member, for values revised after the bulk load.
     *
     * `sortedSetAdd()` buffers a plain insert; a second write of the same {_key,value} would break
     * the unique index NodeBB builds on boot, so revisions upsert instead.
     *
     * @param string $key The sorted set's `_key`.
     * @param int|string $value The member.
     * @param int|float $score The sort score.
     */
    protected function sortedSetUpdate(string $key, int|string $value, int|float $score): void
    {
        $this->mongo()->updateOne(
            self::OBJECTS,
            ['_key' => $key, 'value' => (string)$value],
            ['$set' => ['score' => $score]],
            true
        );
    }

    /**
     * Index the objects collection on `_key` so lookups and updates don't scan the collection.
     *
     * NodeBB rebuilds its full index set on upgrade; this just keeps the migration itself fast.
     */
    protected function indexObjects(): void
    {
        $this->mongo()->createIndex(self::OBJECTS, ['_key' => 1]);
    }

    /**
     * Offset applied to comment IDs to reach this migration's pid space.
     *
     * Always clears the posts the target install already has. With the body mode on it clears the
     * synthesized first posts too, which occupy one pid per discussion directly above those.
     *
     * @return int
     */
    protected function pidOffset(): int
    {
        if ($this->pidOffset === null) {
            $this->pidOffset = $this->offset('pid') + ($this->getDiscussionBodyMode()
                ? (int)$this->porterQB()->from('Discussion')->max('DiscussionID')
                : 0);
        }
        return $this->pidOffset;
    }

    /**
     * Convert a SQL datetime to milliseconds since epoch (NodeBB's time format).
     *
     * @param mixed $datetime
     * @return int
     */
    protected function toMillis(mixed $datetime): int
    {
        if (empty($datetime)) {
            return 0;
        }
        $timestamp = strtotime((string)$datetime);
        return ($timestamp === false) ? 0 : $timestamp * 1000;
    }

    /**
     * Current time in milliseconds since epoch.
     *
     * @return int
     */
    protected function now(): int
    {
        return (int)(microtime(true) * 1000);
    }

    /**
     * Slug a value the way NodeBB does (lowercase, non-alphanumerics to single dashes).
     *
     * NodeBB transliterates; this only strips. A name with no ASCII letters at all (Cyrillic, CJK,
     * Greek) would otherwise slug to '' and leave the record unreachable by URL, so fall back to the
     * record's ID. Ugly, but addressable.
     *
     * @param string $value
     * @param string $fallback Used when $value holds nothing sluggable.
     * @return string
     */
    protected function slugify(string $value, string $fallback = ''): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');

        return ($slug === '') ? $fallback : $slug;
    }
}
