<?php

use MongoDB\Database;
use PHPUnit\Framework\TestCase;
use Porter\Config;
use Porter\Factory;
use Porter\Storage;
use Porter\Storage\Mongo;
use Porter\Target\NodeBb;

/**
 * NodeBB's document shape, which nothing else in Porter writes.
 *
 * An upload in NodeBB is a file on disk, a hash keyed on the md5 of its path, sorted sets tying it
 * to a post and an uploader, and a link in the post content. Assert we reproduce all four.
 *
 * NOTE: this rebuilds the `PORT_` tables on the test connection and drops the Mongo `objects`
 * collection, same as a real migration would.
 *
 * @see https://github.com/NodeBB/NodeBB/blob/master/src/posts/uploads.js
 */
class NodeBbTest extends TestCase
{
    /** @var string Connection holding the `PORT_` tables. */
    protected static string $porterAlias = 'test';

    /** @var string Connection for the NodeBB document store, from `test_mongo_alias`. */
    protected static string $mongoAlias = '';

    /** @var string|null Why these tests cannot safely run here. @see self::findUnsafeAlias() */
    protected static ?string $skip = null;

    /** @var string Stands in for the target install's root during mapping. */
    public const TARGET_ROOT = '/srv/target';

    /** @var string Stands in for the source install's upload folder. */
    public const SOURCE_UPLOADS = '/srv/source/uploads';

    /** @var int The seeded discussion's opening post, as a Comment row. */
    public const OP_COMMENT_ID = 50;

    /** @var int The seeded discussion's one reply. */
    public const REPLY_COMMENT_ID = 51;

    /** @var int Offset applied to CommentIDs when the OP came off the discussion record. */
    public const PID_OFFSET = 1; // max(DiscussionID)

    /** @var int A UserRole pointing at a User row that does not exist. */
    public const DELETED_USER_ID = 99;

    /** @var int A seeded attachment with no source file to copy. */
    public const UNTRANSFERABLE_MEDIA_ID = 3;

    /** @var int A comment authored by user 0 (guest/authorless in Vanilla). */
    public const GUEST_COMMENT_ID = 60;

    /**
     * Shared fixture that runs exactly once prior to ALL the tests in this class.
     */
    public static function setUpBeforeClass(): void
    {
        $config = loadConfig();
        self::$porterAlias = $config['test_alias'] ?? '';
        // Named explicitly, never discovered: scanning for "a mongodb connection" finds the
        // operator's real NodeBB, and these tests clear whatever they are pointed at.
        self::$mongoAlias = $config['test_mongo_alias'] ?? '';
        self::$skip = self::findUnsafeAlias($config);

        // `getPath()` reads this to build an absolute destination.
        // Config::set() replaces wholesale, so put the loaded config back alongside it.
        Config::getInstance()->set(array_merge($config, ['target_root' => self::TARGET_ROOT]));
    }

    protected function setUp(): void
    {
        if (self::$skip !== null) {
            $this->markTestSkipped(self::$skip);
        }
    }

    /**
     * Why these connections must not be written to, or null if they are safe.
     *
     * These tests rebuild `PORT_` tables and clear the target Mongo database, so pointing them at
     * anything holding real data destroys it. Refuse rather than trust a comment in the config.
     *
     * @param array $config
     * @return string|null
     */
    protected static function findUnsafeAlias(array $config): ?string
    {
        if (self::$porterAlias === '') {
            return 'Set `test_alias` in config.php to a connection reserved for tests.';
        }
        if (self::$mongoAlias === '') {
            return 'Set `test_mongo_alias` in config.php to a Mongo database reserved for tests.';
        }
        $live = array_filter([
            'input_alias' => $config['input_alias'] ?? null,
            'output_alias' => $config['output_alias'] ?? null,
            'porter_alias' => $config['porter_alias'] ?? null,
        ]);
        foreach ([self::$porterAlias, self::$mongoAlias] as $alias) {
            $clash = array_search($alias, $live, true);
            if ($clash !== false) {
                return "Test connection '$alias' is also `$clash`. These tests clear what they are "
                    . 'pointed at, so reserve separate connections for them.';
            }
        }

        return null;
    }

    /**
     * Build the `PORT_` tables from Porter's own structure, then fill in one topic and its files.
     *
     * @param Storage $storage
     * @param bool $withGuest Add a comment authored by user 0 (guest).
     */
    protected function seed(Storage $storage, bool $withGuest = false): void
    {
        $structure = loadData('structure');
        foreach (['User', 'Role', 'UserRole', 'Category', 'Discussion', 'Comment', 'Media'] as $table) {
            $storage->prepare($table, $structure[$table]);
        }

        $db = $storage->getHandle();
        $db->table('User')->insert([
            [
                'UserID' => 1,
                'Name' => 'Linc',
                'Email' => 'lincoln@example.com',
                'Password' => 'hash',
                'Photo' => 'userpics/avatar.jpg',
                'DateInserted' => '2020-01-01 00:00:00',
                'DateLastActive' => '2020-06-01 00:00:00',
                'SourceAvatarFullPath' => self::SOURCE_UPLOADS . '/userpics/avatar.jpg',
            ],
            // Every deleted user shares one name, which NodeBB will not accept.
            [
                'UserID' => 2, 'Name' => '[Deleted User]', 'Email' => 'a@example.com',
                'Password' => 'hash', 'Photo' => null,
                'DateInserted' => '2020-01-01 00:00:00', 'DateLastActive' => null,
                'SourceAvatarFullPath' => null,
            ],
            [
                'UserID' => 3, 'Name' => '[Deleted User]', 'Email' => 'b@example.com',
                'Password' => 'hash', 'Photo' => null,
                'DateInserted' => '2020-01-01 00:00:00', 'DateLastActive' => null,
                'SourceAvatarFullPath' => null,
            ],
        ]);
        $db->table('Role')->insert(['RoleID' => 1, 'Name' => 'Members']);
        $db->table('UserRole')->insert([
            ['UserID' => 1, 'RoleID' => 1],
            ['UserID' => self::DELETED_USER_ID, 'RoleID' => 1], // Points at no User row.
        ]);
        $db->table('Category')->insert([
            // Vanilla's root category is a placeholder, not a real forum section.
            ['CategoryID' => -1, 'Name' => 'Root', 'UrlCode' => 'root', 'Sort' => 0],
            ['CategoryID' => 1, 'Name' => 'General', 'UrlCode' => 'general', 'Sort' => 1],
        ]);
        $db->table('Discussion')->insert([
            'DiscussionID' => 1,
            'CategoryID' => 1,
            'InsertUserID' => 1,
            'Name' => 'Hello World',
            'Body' => 'This is the [b]opening[/b] post.',
            'Format' => 'BBCode',
            'CountViews' => 7,
            'DateInserted' => '2020-02-01 00:00:00',
            'DateLastComment' => '2020-02-02 00:00:00',
        ]);
        // CommentIDs deliberately don't start at 1: a fixture where they collide with DiscussionID
        // can't tell the two ways of resolving the OP's pid apart.
        $db->table('Comment')->insert([
            [
                'CommentID' => self::OP_COMMENT_ID,
                'DiscussionID' => 1,
                'InsertUserID' => 1,
                'Body' => 'This is the [b]opening[/b] post.',
                'Format' => 'BBCode',
                'DateInserted' => '2020-02-01 00:00:00',
            ],
            [
                'CommentID' => self::REPLY_COMMENT_ID,
                'DiscussionID' => 1,
                'InsertUserID' => 1,
                'Body' => 'This is a reply.',
                'Format' => 'BBCode',
                'DateInserted' => '2020-02-02 00:00:00',
            ],
        ]);
        if ($withGuest) {
            // Vanilla uses user 0 for guest/authorless content; it must not land on a real account.
            $db->table('Comment')->insert([
                'CommentID' => self::GUEST_COMMENT_ID,
                'DiscussionID' => 1,
                'InsertUserID' => 0,
                'Body' => 'Posted by a guest.',
                'Format' => 'BBCode',
                'DateInserted' => '2020-02-03 00:00:00',
            ]);
        }
        // One file on the discussion and one (an image) on a comment, so both paths get exercised.
        $db->table('Media')->insert([
            [
                'MediaID' => 1,
                'Name' => 'report.pdf',
                'Path' => 'attachments/report.pdf',
                'Type' => 'application/pdf',
                'InsertUserID' => 1,
                'DateInserted' => '2020-02-01 00:00:00',
                'ForeignID' => 1,
                'ForeignTable' => 'discussion',
                'ImageWidth' => null,
                'ImageHeight' => null,
                'SourceFullPath' => self::SOURCE_UPLOADS . '/attachments/report.pdf',
            ],
            [
                'MediaID' => 2,
                'Name' => 'photo.png',
                'Path' => 'attachments/photo.png',
                'Type' => 'image/png',
                'InsertUserID' => 1,
                'DateInserted' => '2020-02-02 00:00:00',
                'ForeignID' => self::REPLY_COMMENT_ID,
                'ForeignTable' => 'Comment',
                'ImageWidth' => 640,
                'ImageHeight' => 480,
                'SourceFullPath' => self::SOURCE_UPLOADS . '/attachments/photo.png',
            ],
            [   // No source file, so no file will ever arrive for it.
                'MediaID' => self::UNTRANSFERABLE_MEDIA_ID,
                'Name' => 'missing.zip',
                'Path' => 'attachments/missing.zip',
                'Type' => 'application/zip',
                'InsertUserID' => 1,
                'DateInserted' => '2020-02-03 00:00:00',
                'ForeignID' => self::REPLY_COMMENT_ID,
                'ForeignTable' => 'comment',
                'ImageWidth' => null,
                'ImageHeight' => null,
                'SourceFullPath' => null,
            ],
        ]);
    }

    /**
     * Seed and run a full migration into the document store.
     *
     * @param bool $useDiscussionBody Whether the OP arrives on the discussion record.
     * @return Database
     */
    protected function migrate(
        bool $useDiscussionBody = true,
        array $installed = [],
        array $sets = [],
        bool $withGuest = false
    ): Database {
        $porterStorage = Factory::storage(self::$porterAlias, 'PORT_');
        $this->seed($porterStorage, $withGuest);

        $outputStorage = Factory::storage(self::$mongoAlias);
        $this->assertInstanceOf(Mongo::class, $outputStorage, 'Expected a document store.');

        // The Target appends to an installed NodeBB and no longer clears anything, so the fixture
        // is ours to reset. $installed stands in for whatever NodeBB's own setup had written, seeded
        // through the same object model the Target uses so we never assume the document shape here.
        $outputStorage->drop(NodeBb::OBJECTS);
        foreach ($installed as $key => $fields) {
            $outputStorage->insert(NodeBb::OBJECTS, array_merge(['_key' => $key], $fields));
        }
        foreach ($sets as [$key, $value, $score]) {
            $outputStorage->insert(NodeBb::OBJECTS, ['_key' => $key, 'value' => (string)$value, 'score' => $score]);
        }
        $outputStorage->flush();

        $target = Factory::target('NodeBb', $porterStorage, $outputStorage);
        // Deliberately no enableFileTransfer(): a Vanilla source has none, and attachments must
        // migrate anyway. The one test that needs a mapping runs filemap() itself.
        if (!$useDiscussionBody) {
            $target->skipDiscussionBody();
        }

        // Buffered because Log::comment() echoes its progress, which PHPUnit counts as risky.
        ob_start();
        $target->validate();
        $target->run();
        $outputStorage->end(); // Normally called by Controller::doImport().
        ob_end_clean();

        return $outputStorage->getHandle();
    }

    /**
     * Read one object back out of NodeBB's single collection.
     *
     * @param Database $db
     * @param string $key
     * @return array|null
     */
    protected function getObject(Database $db, string $key): ?array
    {
        $document = $db->getCollection(NodeBb::OBJECTS)
            ->findOne(['_key' => $key], ['typeMap' => ['root' => 'array']]);
        return $document === null ? null : (array)$document;
    }

    /**
     * The mapped destination for a seeded attachment, as a NodeBB-relative upload path.
     *
     * Restates the rule rather than calling the Target: NodeBB keys uploads on this exact string.
     *
     * @param int $mediaID
     * @return string
     */
    protected function getUploadPath(int $mediaID): string
    {
        $path = Factory::storage(self::$porterAlias, 'PORT_')->getHandle()
            ->table('Media')->where('MediaID', $mediaID)->value('Path');
        $this->assertNotNull($path, "MediaID $mediaID should have a source path.");

        return '/files/' . ltrim((string)$path, '/');
    }

    public function testWritesUploadHashKeyedOnPath(): void
    {
        $db = $this->migrate();
        $path = $this->getUploadPath(1);

        $upload = $this->getObject($db, 'upload:' . md5($path));
        $this->assertNotNull($upload, 'Upload should be keyed on the md5 of its relative path.');
        $this->assertEquals(1, $upload['uid'], 'Upload should record its uploader.');
    }

    public function testWritesImageDimensionsForImagesOnly(): void
    {
        $db = $this->migrate();

        $image = $this->getObject($db, 'upload:' . md5($this->getUploadPath(2)));
        $this->assertEquals(640, $image['width']);
        $this->assertEquals(480, $image['height']);

        // NodeBB only stores dimensions for images. @see `Posts.uploads.saveSize()`
        $document = $this->getObject($db, 'upload:' . md5($this->getUploadPath(1)));
        $this->assertArrayNotHasKey('width', $document, 'Only images should carry dimensions.');
    }

    public function testAssociatesUploadWithItsPostAndUploader(): void
    {
        $db = $this->migrate();
        $path = $this->getUploadPath(1);

        // The discussion's file belongs to the OP, whose pid is the topic ID in this mode.
        $pids = $this->getObject($db, 'upload:' . md5($path) . ':pids');
        $this->assertEquals('1', $pids['value'], 'Upload should be associated with its post.');

        // A comment's file follows the comment, which is offset past the reused topic IDs.
        $reply = $this->getObject($db, 'upload:' . md5($this->getUploadPath(2)) . ':pids');
        $this->assertEquals(
            (string)(self::REPLY_COMMENT_ID + self::PID_OFFSET),
            $reply['value'],
            'A comment attachment should follow its comment.'
        );

        $uploads = $this->getObject($db, 'uid:1:uploads');
        $this->assertEquals($path, $uploads['value'], 'Upload should be listed against its uploader.');
    }

    public function testWritesPostUploadsAsJsonString(): void
    {
        $db = $this->migrate();

        // NodeBB stores this as a JSON string, not an array. @see `Posts.uploads.associate()`
        $post = $this->getObject($db, 'post:1');
        $this->assertIsString($post['uploads'], 'post.uploads should be a JSON string.');
        $this->assertEquals([$this->getUploadPath(1)], json_decode($post['uploads']));
    }

    public function testLinksAttachmentsInPostContent(): void
    {
        $db = $this->migrate();

        // Uploads are only visible to readers as links in the content.
        $post = $this->getObject($db, 'post:1');
        $this->assertStringContainsString(
            '<a href="/assets/uploads' . $this->getUploadPath(1) . '">report.pdf</a>',
            $post['content'],
            'A non-image attachment should render as a link.'
        );
        $this->assertStringContainsString('This is the <b>opening</b> post.', $post['content']);

        $comment = $this->getObject($db, 'post:' . (self::REPLY_COMMENT_ID + self::PID_OFFSET));
        $this->assertStringContainsString(
            '<img src="/assets/uploads' . $this->getUploadPath(2) . '" alt="photo.png" />',
            $comment['content'],
            'An image attachment should render inline.'
        );
    }

    /**
     * A sorted set's members, as plain strings.
     *
     * @param Database $db
     * @param string $key
     * @return array
     */
    protected function getSetMembers(Database $db, string $key): array
    {
        $members = [];
        $cursor = $db->getCollection(NodeBb::OBJECTS)
            ->find(['_key' => $key], ['typeMap' => ['root' => 'array']]);
        foreach ($cursor as $document) {
            $members[] = (string)((array)$document)['value'];
        }
        sort($members);

        return $members;
    }

    /**
     * NodeBB renders the OP from `mainPid` and the replies from `tid:{tid}:posts`. A first post in
     * both shows up twice on the topic page.
     */
    public function testFirstPostIsNotAlsoListedAsAReply(): void
    {
        $db = $this->migrate();
        $tid = 1;

        $mainPid = $this->getObject($db, "topic:$tid")['mainPid'];
        $replies = $this->getSetMembers($db, "tid:$tid:posts");

        $this->assertNotEmpty($replies, 'Replies should be listed for the topic.');
        $this->assertNotContains((string)$mainPid, $replies, 'The OP must not also list as a reply.');
        $this->assertEquals(
            [(string)(self::OP_COMMENT_ID + self::PID_OFFSET), (string)(self::REPLY_COMMENT_ID + self::PID_OFFSET)],
            $replies,
            'Both comments are replies when the OP came off the discussion record.'
        );
    }

    /**
     * With the body mode off the OP is one of the comments, so that one has to be held back.
     *
     * The body-mode case cannot catch this on its own: there, every comment really is a reply.
     */
    public function testFirstPostIsNotAlsoListedAsAReplyWithoutDiscussionBody(): void
    {
        $db = $this->migrate(false);
        $tid = 1;

        $this->assertEquals(
            self::OP_COMMENT_ID,
            $this->getObject($db, "topic:$tid")['mainPid'],
            'The earliest comment is the OP.'
        );
        $this->assertEquals(
            [(string)self::REPLY_COMMENT_ID],
            $this->getSetMembers($db, "tid:$tid:posts"),
            'Only the later comment is a reply; the OP renders from mainPid.'
        );
    }

    /**
     * NodeBB indexes {_key,value} unique, so writing one pair twice aborts the whole migration.
     *
     * `groups:visible:memberCount` is the trap: the Target seeds a group, then revises its count
     * in cleanup(), and the install already holds entries for its own system groups.
     */
    public function testWritesNoDuplicateSortedSetMembers(): void
    {
        $db = $this->migrate(
            true,
            ['group:registered-users' => ['name' => 'registered-users', 'memberCount' => 1],
             'global' => ['nextUid' => 1, 'nextCid' => 1, 'nextTid' => 0, 'nextPid' => 0]],
            // The install already lists its own groups here; cleanup() must re-score, not re-add.
            [['groups:visible:memberCount', 'registered-users', 1]]
        );

        $dupes = $db->getCollection(NodeBb::OBJECTS)->aggregate([
            ['$match' => ['value' => ['$exists' => true]]],
            ['$group' => ['_id' => ['k' => '$_key', 'v' => '$value'], 'n' => ['$sum' => 1]]],
            ['$match' => ['n' => ['$gt' => 1]]],
            ['$limit' => 5],
        ])->toArray();
        $this->assertEmpty(
            array_map(fn($d) => (array)((array)$d)['_id'], $dupes),
            'A repeated {_key,value} breaks the unique index NodeBB builds on boot.'
        );
    }

    /**
     * Guest-authored content (Vanilla user 0) must not be attributed to a real account.
     *
     * With an install present the uid offset is non-zero, so a naive `0 + offset` lands the guest's
     * posts on whichever user occupies that uid — the admin. Guest stays uid 0 in NodeBB.
     */
    public function testAttributesGuestContentToGuestNotAdmin(): void
    {
        // Install admin at uid 1, so the offset is 1 and the bug would map guest -> uid 1.
        $db = $this->migrate(
            true,
            ['user:1' => ['uid' => 1, 'username' => 'admin'],
             'global' => ['nextUid' => 1, 'nextCid' => 1, 'nextTid' => 0, 'nextPid' => 0]],
            [],
            true
        );

        // The guest comment's post is authored by guest (uid 0), not the admin.
        $guestPid = self::GUEST_COMMENT_ID + self::PID_OFFSET;
        $this->assertEquals(0, $this->getObject($db, "post:$guestPid")['uid'], 'Guest post stays uid 0.');

        // The admin must own no migrated content, and its counts must be untouched.
        $adminPosts = $db->getCollection(NodeBb::OBJECTS)
            ->countDocuments(['_key' => ['$regex' => '^post:'], 'uid' => 1]);
        $this->assertEquals(0, $adminPosts, 'No migrated post should be attributed to the admin.');
        $this->assertArrayNotHasKey('postcount', $this->getObject($db, 'user:1'), 'Admin counts stay untouched.');
    }

    /**
     * Vanilla's root category is a placeholder, and every other Target drops it.
     */
    public function testIgnoresTheVanillaRootCategory(): void
    {
        $db = $this->migrate();

        $this->assertNotNull($this->getObject($db, 'category:1'), 'A real category still migrates.');
        $this->assertEquals(
            ['1'],
            $this->getSetMembers($db, 'categories:cid'),
            'Only the real category should be listed.'
        );
    }

    /**
     * A role assignment for a user who no longer exists would add a dead uid to a group.
     */
    public function testPrunesOrphanedRoleAssignments(): void
    {
        $db = $this->migrate();

        $this->assertEquals(
            ['1'],
            $this->getSetMembers($db, 'group:Members:members'),
            'A membership for a missing user should be pruned.'
        );
    }

    /**
     * NodeBB requires unique usernames, and every deleted user arrives sharing one.
     */
    public function testRenamesDuplicateDeletedUsers(): void
    {
        $db = $this->migrate();

        $this->assertEquals('deleted_user_2', $this->getObject($db, 'user:2')['username']);
        $this->assertEquals('deleted_user_3', $this->getObject($db, 'user:3')['username']);

        // Both must be reachable; a shared name would leave the second with no lookup entry.
        $this->assertEqualsCanonicalizing(
            ['Linc', 'deleted_user_2', 'deleted_user_3'],
            $this->getSetMembers($db, 'username:uid'),
            'Every migrated user needs a username lookup.'
        );
    }

    /**
     * Counts NodeBB shows but never recalculates on its own.
     */
    public function testFinalizesCounts(): void
    {
        $db = $this->migrate();

        // 1 synthesized OP + 2 comments.
        $this->assertEquals(3, $this->getObject($db, 'topic:1')['postcount'], 'topic.postcount');
        $this->assertEquals(1, $this->getObject($db, 'category:1')['topic_count'], 'category.topic_count');
        $this->assertEquals(3, $this->getObject($db, 'category:1')['post_count'], 'category.post_count');

        $user = $this->getObject($db, 'user:1');
        $this->assertEquals(1, $user['topiccount'], 'user.topiccount');
        $this->assertEquals(3, $user['postcount'], 'user.postcount');
    }

    /**
     * With the body mode off the OP is already a comment, so counting it again inflates everything.
     */
    public function testCountsDoNotDoubleCountTheOpAsAComment(): void
    {
        $db = $this->migrate(false);

        $this->assertEquals(2, $this->getObject($db, 'topic:1')['postcount'], 'topic.postcount');
        $this->assertEquals(2, $this->getObject($db, 'category:1')['post_count'], 'category.post_count');
        $this->assertEquals(2, $this->getObject($db, 'user:1')['postcount'], 'user.postcount');
    }

    /**
     * NodeBB assigns new IDs from these, so a stale counter overwrites migrated records.
     */
    public function testAdvancesGlobalCountersPastMigratedIds(): void
    {
        $db = $this->migrate();
        $global = $this->getObject($db, 'global');

        $this->assertGreaterThan(
            (int)$this->getObject($db, 'topic:1')['mainPid'],
            (int)$global['nextPid'] + 1,
            'The next pid NodeBB assigns must clear every migrated post.'
        );
        $highestPid = self::REPLY_COMMENT_ID + self::PID_OFFSET;
        $this->assertEquals($highestPid, $global['nextPid'], 'nextPid');
        $this->assertEquals(3, $global['nextUid'], 'nextUid');
        $this->assertEquals(1, $global['nextTid'], 'nextTid');
        $this->assertEquals(1, $global['topicCount'], 'topicCount');
        $this->assertEquals(3, $global['postCount'], 'postCount');
    }

    /**
     * Every NodeBB user must be in `registered-users` or they have no privileges anywhere.
     */
    public function testAddsUsersToRegisteredUsers(): void
    {
        $db = $this->migrate();

        $this->assertEquals(
            ['1', '2', '3'],
            $this->getSetMembers($db, 'group:registered-users:members'),
            'Migrated users must join registered-users.'
        );
    }

    /**
     * The Target appends to an installed NodeBB; wiping its config or admin breaks the forum.
     */
    public function testPreservesAnExistingInstall(): void
    {
        // Stands in for what NodeBB's own setup wrote: config, the admin, a default category.
        $db = $this->migrate(true, [
            'config' => ['title' => 'Existing Forum'],
            'user:1' => ['uid' => 1, 'username' => 'admin'],
            'category:1' => ['cid' => 1, 'name' => 'Announcements'],
            'group:registered-users' => ['name' => 'registered-users', 'memberCount' => 1],
            // As NodeBB writes them: the highest id already assigned.
            'global' => ['nextUid' => 1, 'nextCid' => 1, 'nextTid' => 0, 'nextPid' => 0],
        ]);

        $this->assertEquals('Existing Forum', $this->getObject($db, 'config')['title'], 'Config survives.');
        $this->assertEquals('admin', $this->getObject($db, 'user:1')['username'], 'The admin survives.');
        $this->assertEquals('Announcements', $this->getObject($db, 'category:1')['name'], 'Its category survives.');

        // Migrated records start above the IDs the install had already assigned.
        $this->assertEquals('Linc', $this->getObject($db, 'user:2')['username'], 'Migrated user is offset.');
        $this->assertEquals('General', $this->getObject($db, 'category:2')['name'], 'Migrated category is offset.');
        $this->assertEquals(2, $this->getObject($db, 'topic:1')['cid'], 'Topic points at the offset category.');
        $this->assertEquals(2, $this->getObject($db, 'topic:1')['uid'], 'Topic points at the offset user.');

        // The install's own records must be untouched by the ones written around them.
        $this->assertEquals(1, $this->getObject($db, 'user:1')['uid'], 'The admin keeps uid 1.');

        // The install's own member is still counted alongside ours.
        $this->assertEquals(
            4,
            $this->getObject($db, 'group:registered-users')['memberCount'],
            'memberCount adds to the install.'
        );
    }

    /**
     * Duplicate or reserved group names break NodeBB's unique index, which stops it booting.
     */
    public function testSkipsDuplicateAndReservedGroupNames(): void
    {
        $storage = Factory::storage(self::$porterAlias, 'PORT_');
        $db = $this->migrate();

        // Two roles sharing a name, plus one shadowing a NodeBB system group.
        $storage->getHandle()->table('Role')->insert([
            ['RoleID' => 2, 'Name' => 'Members'],
            ['RoleID' => 3, 'Name' => 'administrators'],
        ]);
        $db = $this->migrate();

        $groups = $this->getSetMembers($db, 'groups:createtime');
        $this->assertEquals(['Members'], $groups, 'Only the first non-reserved role becomes a group.');

        // A second `{_key,value}` for one group is what makes NodeBB's index build fail.
        $duplicates = $db->getCollection(NodeBb::OBJECTS)->countDocuments(['_key' => 'group:Members']);
        $this->assertEquals(1, $duplicates, 'A group must be written exactly once.');
    }

    /**
     * Attachment records do not depend on the files being moved, same as every other Target.
     *
     * A file transfer is a separate step the operator may or may not run; Flarum and Agorakit both
     * import their attachment rows from `Media.Path` regardless. Gating on it here would mean a
     * Vanilla source (which has no file transfer support) migrated no attachments at all.
     */
    public function testMigratesAttachmentsWithoutAFileTransfer(): void
    {
        $db = $this->migrate();

        $this->assertNull(
            Factory::storage(self::$porterAlias, 'PORT_')->getHandle()->table('Media')
                ->where('MediaID', self::UNTRANSFERABLE_MEDIA_ID)->value('TargetFullPath'),
            'Nothing should have been mapped without a file transfer.'
        );

        // Every attachment on a migrated post still becomes an upload.
        $uploads = $db->getCollection(NodeBb::OBJECTS)
            ->countDocuments(['_key' => ['$regex' => '^upload:[0-9a-f]{32}$']]);
        $this->assertEquals(3, $uploads, 'All three attachments should become uploads.');

        $reply = $this->getObject($db, 'post:' . (self::REPLY_COMMENT_ID + self::PID_OFFSET));
        $this->assertStringContainsString('missing.zip', $reply['content'], 'Its link is still written.');
    }

    /**
     * The upload path has to match where a file transfer would put the file, or links break.
     */
    public function testUploadPathMatchesTheFileTransferDestination(): void
    {
        $storage = Factory::storage(self::$porterAlias, 'PORT_');
        $this->migrate();

        // Run the mapping the file transfer would use.
        $target = Factory::target('NodeBb', $storage, Factory::storage(self::$mongoAlias));
        $target->enableFileTransfer();
        ob_start();
        new ReflectionMethod($target, 'filemap')->invoke($target);
        ob_end_clean();

        $mapped = $storage->getHandle()->table('Media')->where('MediaID', 1)->value('TargetFullPath');
        $this->assertStringEndsWith(
            $this->getUploadPath(1),
            (string)$mapped,
            'The copied file must land at the path the upload record points to.'
        );
    }

    /**
     * With the body mode off the OP is a comment, so attachments have to follow it there.
     */
    public function testResolvesAttachmentPidWithoutDiscussionBody(): void
    {
        $db = $this->migrate(false);

        // Nothing synthesizes a post at the topic ID, and comment pids pass through unoffset.
        $this->assertNull($this->getObject($db, 'post:1'), 'No post should reuse the topic ID in this mode.');
        $this->assertEquals(
            self::OP_COMMENT_ID,
            $this->getObject($db, 'topic:1')['mainPid'],
            'mainPid should be the earliest comment.'
        );

        // The discussion's file has to land on that comment, not on the discussion ID.
        $op = $this->getObject($db, 'post:' . self::OP_COMMENT_ID);
        $this->assertEquals(
            [$this->getUploadPath(1)],
            json_decode($op['uploads']),
            "A discussion attachment should resolve onto the OP's comment."
        );

        $reply = $this->getObject($db, 'post:' . self::REPLY_COMMENT_ID);
        $this->assertEqualsCanonicalizing(
            [$this->getUploadPath(2), $this->getUploadPath(self::UNTRANSFERABLE_MEDIA_ID)],
            json_decode($reply['uploads']),
            'Comment attachments should stay on their comment.'
        );
    }
}
