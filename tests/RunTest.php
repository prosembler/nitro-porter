<?php

use PHPUnit\Framework\TestCase;
use Porter\Config;
use Porter\Factory;
use Porter\Package;
use Porter\Request;
use Porter\Schema;

class RunTest extends TestCase
{
    public const array NONSCHEMA_TARGETS = ['Discourse', 'NodeBb'];

    public static function allPackageRuns(): Generator
    {
        $testAlias = Config::getInstance()->getTestConnection()['alias'];
        $sources = Package::list('sources');
        $targets = Package::list('targets');
        foreach ($sources as $source) {
            $srcPrefix = 'test_src_' . strtolower($source) . '_';

            // Build source schemas.
            $srcSchema = Schema::load($source, true);
            if (empty($srcSchema)) {
                continue; // @todo log a skipped test
            }
            $storage = Factory::storage($testAlias, $srcPrefix);
            foreach ($srcSchema as $table => $schema) {
                $storage->prepare($table, $schema);
            }

            // Run a request PER TARGET.
            foreach ($targets as $target) {
                if (in_array($target, self::NONSCHEMA_TARGETS, true)) {
                    continue; // Skip non-schema targets (Mongo).
                }
                yield [new Request(
                    sourcePackage: $source,
                    targetPackage: $target,
                    inputStorage: $testAlias,
                    outputStorage: $testAlias,
                    inputTablePrefix: $srcPrefix,
                    outputTablePrefix: 'test_tar_' . strtolower($target) . '_',
                )];
            }
        }
    }

    /** @dataProvider allPackageRuns */
    public function testRun(Request $request): void
    {
        ob_start();
        try {
            new \Porter\Controller()->run($request);
        } catch (\Exception $e) {
            $runName = $request->getSource() . '->' . $request->getTarget();
            $this->fail('Failed run ' . $runName . ': ' . $e->getMessage());
        }
        ob_end_clean();
        $this->expectNotToPerformAssertions();
    }
}
