<?php

use PHPUnit\Framework\TestCase;
use Porter\Factory;

class EncodingTest extends TestCase
{
    public const string ENV_ALIAS = 'test';

    /**
     * @throws Exception
     */
    public function testEncodingDetection(): void
    {
        $source = Factory::source(
            self::ENV_ALIAS,
            Factory::storage(self::ENV_ALIAS),
            Factory::storage(self::ENV_ALIAS)
        );

        // Create sample tables with various collations.
        $structure = [
            'Name' => 'varchar(50)',
            'DiscussionID' => 'int',
            'InsertUserID' => 'int',
            'Body' => 'text',
            'DateInserted' => 'datetime',
        ];
        $tables = [
            'EncodingA' => array_merge(['collation' => 'utf8mb4_unicode_ci'], $structure),
            'EncodingB' => array_merge(['collation' => 'latin1_swedish_ci'], $structure),
            'EncodingC' => array_merge(['collation' => 'utf8mb3_general_ci'], $structure),
            'EncodingD' => array_merge(['collation' => 'cp1250_general_ci'], $structure),
        ];
        foreach ($tables as $tableName => $tableInfo) {
            $source->porterStorage->prepare($tableName, $tableInfo);
        }

        // Test our code detects the real collations.
        $tests = [
            'EncodingA' => 'UTF-8', // utf8mb4_unicode_ci
            'EncodingB' => 'ISO-8859-1', // latin1_swedish_ci
            'EncodingC' => 'UTF-8', // utf8mb3_general_ci
            'EncodingD' => 'cp1250', // cp1250_general_ci
        ];
        foreach ($tests as $table => $expected) {
            $encoding = $source->getInputEncoding($table);
            $this->assertEquals($expected, $encoding);
        }
    }
}
