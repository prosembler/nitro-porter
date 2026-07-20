<?php

use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\TestCase;
use Porter\Config;
use Porter\Factory;
use Porter\Storage;
use Porter\Target;

/**
 * The file-mapping contract every attachment-capable Target must honor.
 *
 * `filemap()` is shared, but each Target names its own destination, so assert the behavior they
 * have to agree on rather than the paths they don't.
 *
 * NOTE: this rebuilds the `PORT_` tables on the test connection, same as a real migration would.
 */
class AttachmentsTest extends TestCase
{
    /** @var string Connection the tests run against, from `test_alias`. */
    protected static string $alias = '';

    /** @var string|null Why these tests cannot safely run here. @see self::findUnsafeAlias() */
    protected static ?string $skip = null;

    /** @var string Stands in for the target install's root during mapping. */
    public const TARGET_ROOT = '/srv/target';

    /** @var string Stands in for the source install's upload folder. */
    public const SOURCE_UPLOADS = '/srv/source/uploads';

    /**
     * Shared fixture that runs exactly once prior to ALL the tests in this class.
     */
    public static function setUpBeforeClass(): void
    {
        $config = loadConfig();
        self::$alias = $config['test_alias'] ?? '';
        self::$skip = self::findUnsafeAlias($config, self::$alias);
        if (self::$skip !== null) {
            return; // Nothing is seeded, because seeding is what would do the damage.
        }

        // `getPath('attachment', 'full')` reads this to build an absolute destination.
        // Config::set() replaces wholesale, so put the loaded config back alongside it.
        Config::getInstance()->set(array_merge($config, ['target_root' => self::TARGET_ROOT]));

        self::seed(Factory::storage(self::$alias, 'PORT_'));
    }

    protected function setUp(): void
    {
        if (self::$skip !== null) {
            $this->markTestSkipped(self::$skip);
        }
    }

    /**
     * Why this connection must not be written to, or null if it is safe.
     *
     * These tests rebuild `PORT_` tables, so pointing them at a connection that holds real
     * migration data destroys it. Refuse rather than trust the operator to have read a comment.
     *
     * @param array $config
     * @param string $alias
     * @return string|null
     */
    protected static function findUnsafeAlias(array $config, string $alias): ?string
    {
        if ($alias === '') {
            return 'Set `test_alias` in config.php to a connection reserved for tests.';
        }
        $live = array_filter([
            'input_alias' => $config['input_alias'] ?? null,
            'output_alias' => $config['output_alias'] ?? null,
            'porter_alias' => $config['porter_alias'] ?? null,
        ]);
        $clash = array_search($alias, $live, true);
        if ($clash !== false) {
            return "`test_alias` is '$alias', which is also `$clash`. These tests rebuild tables, "
                . 'so point `test_alias` at a database reserved for them.';
        }

        return null;
    }

    /**
     * Build the `PORT_` tables from Porter's own structure, then fill in one topic and its files.
     *
     * Attachments cover the cases Targets have to tell apart: one on the discussion, one on the
     * comment, one with no source file, and one attached to a record Targets don't migrate.
     *
     * @param Storage $storage
     */
    protected static function seed(Storage $storage): void
    {
        $structure = loadData('structure');
        foreach (['User', 'Category', 'Discussion', 'Comment', 'Media'] as $table) {
            $storage->prepare($table, $structure[$table]);
        }

        $db = $storage->getHandle();
        $db->table('User')->insert([
            'UserID' => 1,
            'Name' => 'Linc',
            'Email' => 'lincoln@example.com',
            'Password' => 'hash',
            'Photo' => 'userpics/avatar.jpg',
            'DateInserted' => '2020-01-01 00:00:00',
            'DateLastActive' => '2020-06-01 00:00:00',
            'SourceAvatarFullPath' => self::SOURCE_UPLOADS . '/userpics/avatar.jpg',
        ]);
        $db->table('Category')->insert([
            'CategoryID' => 1, 'Name' => 'General', 'UrlCode' => 'general', 'Sort' => 1,
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
        $db->table('Comment')->insert([
            'CommentID' => 1,
            'DiscussionID' => 1,
            'InsertUserID' => 1,
            'Body' => 'This is a reply.',
            'Format' => 'BBCode',
            'DateInserted' => '2020-02-02 00:00:00',
        ]);
        // Every row needs the same keys in the same order for a bulk insert.
        $media = [
            [   // On the discussion (the OP).
                'MediaID' => 1,
                'Name' => 'report.pdf',
                'Path' => 'attachments/report.pdf',
                'Type' => 'application/pdf',
                'DateInserted' => '2020-02-01 00:00:00',
                'ForeignID' => 1,
                'ForeignTable' => 'discussion',
                'ImageWidth' => null,
                'ImageHeight' => null,
                'SourceFullPath' => self::SOURCE_UPLOADS . '/attachments/report.pdf',
            ],
            [   // On the comment, & an image so dimensions can carry over.
                'MediaID' => 2,
                'Name' => 'photo.png',
                'Path' => 'attachments/photo.png',
                'Type' => 'image/png',
                'DateInserted' => '2020-02-02 00:00:00',
                'ForeignID' => 1,
                'ForeignTable' => 'Comment', // Sources disagree on the case.
                'ImageWidth' => 640,
                'ImageHeight' => 480,
                'SourceFullPath' => self::SOURCE_UPLOADS . '/attachments/photo.png',
            ],
            [   // No source file, so there is nothing to transfer & nothing to map.
                'MediaID' => 3,
                'Name' => 'missing.zip',
                'Path' => 'attachments/missing.zip',
                'Type' => 'application/zip',
                'DateInserted' => '2020-02-03 00:00:00',
                'ForeignID' => 1,
                'ForeignTable' => 'comment',
                'ImageWidth' => null,
                'ImageHeight' => null,
                'SourceFullPath' => null,
            ],
            [   // Attached to a record none of these Targets migrate.
                'MediaID' => 4,
                'Name' => 'embedded.gif',
                'Path' => 'attachments/embedded.gif',
                'Type' => 'image/gif',
                'DateInserted' => '2020-02-04 00:00:00',
                'ForeignID' => 99,
                'ForeignTable' => 'embed',
                'ImageWidth' => null,
                'ImageHeight' => null,
                'SourceFullPath' => self::SOURCE_UPLOADS . '/attachments/embedded.gif',
            ],
        ];
        $db->table('Media')->insert(array_map(fn($row) => $row + ['InsertUserID' => 1], $media));
    }

    /**
     * Every Target declaring attachment support, so new ones get held to this too.
     *
     * @return array
     */
    public static function getAttachmentTargets(): array
    {
        $targets = [];
        foreach (loadData('targets') as $name) {
            $support = ('\Porter\Target\\' . $name)::getSupport();
            if (!empty($support['attachmentPath']) && !empty($support['features']['Attachments'])) {
                $targets[$name] = [$name];
            }
        }
        return $targets;
    }

    /**
     * @dataProvider getAttachmentTargets
     */
    public function testMapsEveryTransferableAttachment(string $name): void
    {
        $target = $this->mapAttachments($name);

        // MediaID 1 and 2 have a source file; 4 does too, even though its parent isn't migrated.
        // Targets differ on whether they also map MediaID 3, which has no source file to copy.
        // That costs nothing, since FileTransfer needs both paths, so don't hold them to it.
        $mapped = $this->getMedia()->whereNotNull('TargetFullPath')->pluck('MediaID')->all();
        foreach ([1, 2, 4] as $mediaID) {
            $this->assertContains(
                $mediaID,
                $mapped,
                "$name should map every attachment holding a source file."
            );
        }

        // The destination has to land inside the folder the Target advertises.
        $attachmentPath = $target::getSupport()['attachmentPath'];
        foreach ($this->getMedia()->whereNotNull('TargetFullPath')->pluck('TargetFullPath') as $path) {
            $this->assertStringStartsWith(
                self::TARGET_ROOT . '/' . $attachmentPath . '/',
                $path,
                "$name should map into its declared attachmentPath."
            );
        }
    }

    /**
     * Two attachments sharing a destination means the transfer silently overwrites one of them.
     *
     * @dataProvider getAttachmentTargets
     */
    public function testMappedAttachmentsAreUnique(string $name): void
    {
        $this->mapAttachments($name);

        $paths = $this->getMedia()->whereNotNull('TargetFullPath')->pluck('TargetFullPath')->all();
        $this->assertSameSize($paths, array_unique($paths), "$name should give every attachment its own path.");
    }

    /**
     * Attachments are only mapped as part of a file transfer.
     *
     * @dataProvider getAttachmentTargets
     */
    public function testSkipsMappingWithoutFileTransfer(string $name): void
    {
        $this->mapAttachments($name, false);

        $this->assertEmpty(
            $this->getMedia()->whereNotNull('TargetFullPath')->pluck('TargetFullPath')->all(),
            "$name should map nothing when no file transfer is configured."
        );
    }

    /**
     * Run a Target's file mapping over the seeded attachments.
     *
     * @param string $name
     * @param bool $transferFiles
     * @return Target
     */
    protected function mapAttachments(string $name, bool $transferFiles = true): Target
    {
        $this->getMedia()->update(['TargetFullPath' => null]); // Reset between Targets.

        $storage = Factory::storage(self::$alias, 'PORT_');
        $target = Factory::target($name, $storage, $storage);
        if ($transferFiles) {
            $target->enableFileTransfer(); // Normally set by Controller::setFlags().
        }

        // `filemap()` is a MANIFEST step, so run() is the only way in during a real migration.
        // Buffered because Log::comment() echoes its progress, which PHPUnit counts as risky.
        ob_start();
        new ReflectionMethod($target, 'filemap')->invoke($target);
        ob_end_clean();

        return $target;
    }

    /**
     * A fresh query builder over the seeded attachments.
     *
     * @return Builder
     */
    protected function getMedia(): Builder
    {
        return Factory::storage(self::$alias, 'PORT_')->getHandle()->table('Media')->orderBy('MediaID');
    }
}
