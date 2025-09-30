<?php

/**
 *
 */

namespace Porter;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

/**
 * Monolog wrapper
 * @see http://seldaek.github.io/monolog/doc/01-usage.html
 */
class Log
{
    public static ?Logger $logger = null;

    /**
     * Only need one logger for now.
     *
     * @return Logger
     */
    public static function getInstance(): Logger
    {
        if (self::$logger !== null) {
            return self::$logger;
        }

        // Create the logger
        self::$logger = new Logger('porter');

        // Add handlers
        self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../porter.log', Logger::DEBUG));
        self::$logger->pushHandler(new FirePHPHandler());

        return self::$logger;
    }

    /**
     * Write a comment to the log & console.
     *
     * @param string $message The message to write.
     * @param bool $echo Whether or not to echo the message in addition to writing it to the file.
     */
    public static function comment(string $message, bool $echo = true): void
    {
        self::getInstance()->info($message);
        if ($echo) {
            echo "\n" . $message;
        }
    }

    /**
     * Add log with results of a table storage action.
     *
     * @param string $action
     * @param string $table
     * @param float $timeElapsed
     * @param int $rowCount
     * @param int $memPeak
     */
    public static function storage(string $action, string $table, float $timeElapsed, int $rowCount, int $memPeak): void
    {
        // Format output.
        $report = sprintf(
            '%s: %s — %d rows, %s (%s)',
            $action,
            $table,
            $rowCount,
            formatElapsed($timeElapsed),
            formatBytes($memPeak)
        );
        Log::comment($report);
    }
}
