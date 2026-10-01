<?php

use PHPUnit\Framework\TestCase;
use Porter\Config;
use Porter\Factory;

class EncodingTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testEncodingDetection(): void
    {
        $alias = Config::getInstance()->getTestConnection()['alias'];
        $source = Factory::source('ExampleSource', Factory::storage($alias), Factory::storage($alias));

        // Create sample tables with various collations.
        $structure = [
            'Name' => 'varchar(50)',
            'DiscussionID' => 'int',
            'InsertUserID' => 'int',
            'Body' => 'text',
            'DateInserted' => 'datetime',
        ];
        $tables = [
            'EncodingA' => array_merge(['collation' => 'utf8mb4_unicode_ci', 'charset' => 'utf8mb4'], $structure),
            'EncodingB' => array_merge(['collation' => 'latin1_swedish_ci', 'charset' => 'latin1'], $structure),
            'EncodingC' => array_merge(['collation' => 'utf8mb3_general_ci', 'charset' => 'utf8mb3'], $structure),
            'EncodingD' => array_merge(['collation' => 'cp1250_general_ci', 'charset' => 'cp1250'], $structure),
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
