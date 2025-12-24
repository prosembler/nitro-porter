<?php

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class CoreTest extends TestCase
{
    public const ENV_ALIAS = 'test';

    /**
     * Shared fixture that runs exactly once prior to ALL the tests in this class.
     *
     * @see https://book.cakephp.org/phinx/0/en/commands.html
     * "PDOException: SQLSTATE[42S02]: Base table or view not found" = `truncate table phinxlog`
     */
    public static function setUpBeforeClass(): void
    {
        $configArray = array_merge(require('tests/integration/phinx.php'), ['paths' => [
            'migrations' => __DIR__ . '/migrations/Core',
        ]]);
        $config = new Config($configArray);
        $manager = new Manager($config, new StringInput(' '), new NullOutput());
        $manager->migrate(self::ENV_ALIAS);
    }

    /**
     * @throws Exception
     */
    public function testEncodingDetection(): void
    {
        $port = migrationFactory(self::ENV_ALIAS, self::ENV_ALIAS);
        $tests = [
            'EncodingA' => 'UTF-8', // utf8mb4_unicode_ci
            'EncodingB' => 'ISO-8859-1', // latin1_swedish_ci
            'EncodingC' => 'UTF-8', // utf8mb3_general_ci
            'EncodingD' => 'cp1250', // cp1250_general_ci
        ];
        foreach ($tests as $table => $expected) {
            $encoding = $port->getInputEncoding($table);
            $this->assertEquals($expected, $encoding);
        }
    }
}
