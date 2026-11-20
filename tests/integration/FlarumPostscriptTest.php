<?php

namespace Porter\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Porter\Config;
use Porter\Factory;
use Porter\Postscript\Flarum;

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__, 2));
}

class FlarumPostscriptTest extends TestCase
{
    protected static string $alias = '';

    protected static string $prefix = '';

    protected static ?string $skip = null;

    public static function setUpBeforeClass(): void
    {
        $config = Config::loadFile();
        Config::getInstance()->setAll($config);
        self::$alias = $config['test_alias'] ?? '';
        self::$skip = self::findUnsafeAlias($config, self::$alias);
        self::$prefix = 'ps' . bin2hex(random_bytes(4)) . '_';
    }

    protected function setUp(): void
    {
        if (self::$skip !== null) {
            $this->markTestSkipped(self::$skip);
        }
    }

    public function testPostNumbersCanBeRebuiltWhenExistingNumbersDifferFromChronology(): void
    {
        $storage = Factory::storage(self::$alias, self::$prefix);
        $connection = $storage->getHandle();
        $schema = $connection->getSchemaBuilder();
        $schema->create('posts', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('discussion_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('type', 100);
            $table->boolean('is_private')->default(false);
            $table->unsignedInteger('number')->nullable();
            $table->dateTime('created_at');
            $table->unique(['discussion_id', 'number']);
        });
        $schema->create('discussions', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('comment_count')->default(0);
            $table->unsignedInteger('post_number_index')->default(0);
        });
        $schema->create('users', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('comment_count')->default(0);
        });

        try {
            $connection->table('discussions')->insert([
                ['id' => 1, 'comment_count' => 0, 'post_number_index' => 3],
                ['id' => 2, 'comment_count' => 0, 'post_number_index' => 3],
            ]);
            $connection->table('users')->insert([
                ['id' => 1, 'comment_count' => 0],
                ['id' => 2, 'comment_count' => 0],
            ]);
            $connection->table('posts')->insert([
                ['id' => 11, 'discussion_id' => 1, 'user_id' => 1, 'type' => 'comment', 'is_private' => false,
                    'number' => 1,
                    'created_at' => '2020-02-02 00:00:00'],
                ['id' => 12, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'is_private' => false,
                    'number' => 2,
                    'created_at' => '2020-02-01 00:00:00'],
                ['id' => 21, 'discussion_id' => 2, 'user_id' => 1, 'type' => 'comment', 'is_private' => false,
                    'number' => null,
                    'created_at' => '2020-02-01 00:00:00'],
                ['id' => 22, 'discussion_id' => 2, 'user_id' => 2, 'type' => 'comment', 'is_private' => false,
                    'number' => null,
                    'created_at' => '2020-02-02 00:00:00'],
                ['id' => 23, 'discussion_id' => 2, 'user_id' => 1, 'type' => 'comment', 'is_private' => true,
                    'number' => null, 'created_at' => '2020-02-03 00:00:00'],
            ]);

            $postscript = new Flarum(
                Factory::storage(self::$alias, self::$prefix),
                Factory::storage(self::$alias, self::$prefix),
                'Flarum'
            );
            $renumber = new \ReflectionMethod($postscript, 'numberPosts');
            $countUsers = new \ReflectionMethod($postscript, 'numberUserComments');
            ob_start();
            try {
                $renumber->invoke($postscript);
                $renumber->invoke($postscript);
                $countUsers->invoke($postscript);
                $countUsers->invoke($postscript);
            } finally {
                ob_end_clean();
            }

            $numbers = $connection->table('posts')->orderBy('id')->pluck('number', 'id')->all();
            $this->assertSame([11 => 2, 12 => 1, 21 => 1, 22 => 2, 23 => 3], $numbers);
            $discussions = $connection->table('discussions')->orderBy('id')
                ->get(['id', 'comment_count', 'post_number_index'])->keyBy('id');
            $this->assertSame(1, $discussions[1]->comment_count);
            $this->assertSame(3, $discussions[1]->post_number_index);
            $this->assertSame(2, $discussions[2]->comment_count);
            $this->assertSame(4, $discussions[2]->post_number_index);
            $userCounts = $connection->table('users')->orderBy('id')->pluck('comment_count', 'id')->all();
            $this->assertSame([1 => 2, 2 => 2], $userCounts);
        } finally {
            $schema->dropIfExists('posts');
            $schema->dropIfExists('discussions');
            $schema->dropIfExists('users');
        }
    }

    private static function findUnsafeAlias(array $config, string $alias): ?string
    {
        if ($alias === '') {
            return 'Set `test_alias` in config.php to a connection reserved for tests.';
        }

        $liveAliases = array_filter([
            $config['input_alias'] ?? null,
            $config['output_alias'] ?? null,
            $config['porter_alias'] ?? null,
        ]);
        if (in_array($alias, $liveAliases, true)) {
            return "`test_alias` must not point to a migration input or output connection.";
        }

        return null;
    }
}
