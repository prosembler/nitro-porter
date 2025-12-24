<?php

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class VanillaTest extends TestCase
{
    /**
     * Shared fixture that runs exactly once prior to ALL the tests in this class.
     *
     * @see https://book.cakephp.org/phinx/0/en/commands.html
     *  "PDOException: SQLSTATE[42S02]: Base table or view not found" = `truncate table phinxlog`
     */
    public static function setUpBeforeClass(): void
    {
        $configArray = array_merge(require('tests/integration/phinx.php'), ['paths' => [
            'migrations' => __DIR__ . '/migrations/Vanilla',
            'seeds' => __DIR__ . '/seeds/Vanilla',
        ]]);
        $config = new Config($configArray);
        $manager = new Manager($config, new StringInput(' '), new NullOutput());
        $manager->migrate('test');
        $manager->seed('test');
    }
}
