<?php

/**
 *
 */

namespace Porter;

use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Monolog wrapper
 * @see http://seldaek.github.io/monolog/doc/01-usage.html
 */
class Log
{
    public static ?Logger $logger = null;

    /**
     * Only need one logger for now.
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
     */
    public static function storage(string $action, string $table, float $timeElapsed, int $rowCount, int $memPeak): void
    {
        // Format output.
        $report = sprintf(
            '%s: %s — %d rows, %s (%s)',
            $action,
            $table,
            $rowCount,
            Log::formatElapsed($timeElapsed),
            Log::formatBytes($memPeak)
        );
        Log::comment($report);
    }

    /**
     * Add log with results of a 'pull' action.
     */
    public static function pull(string $table, array $info): void
    {
        // Format output.
        $report = sprintf(
            'pull: %s — %d rows — GET (%s)%s [%s], %s (%s)',
            $table,
            count($info['content']),
            $info['endpoint'],
            (!empty($info['query'])) ? json_encode($info['query']) : '',
            $info['http_code'],
            Log::formatElapsed($info['pull_time']),
            Log::formatBytes($info['memory'])
        );
        Log::comment($report);
    }

    /**
     * Add log with results of file downalods.
     * @see \Porter\Storage\Https::asyncDownload()x
     */
    public static function download(
        int $memory = 0,
        float $elapsed = 0,
        int $countRequest = 0,
        int $countResponse = 0,
    ): void {
        $bytes = Log::formatBytes($memory);
        $time = Log::formatElapsed($elapsed);
        Log::comment("download: {$countResponse}/{$countRequest} files in $time ($bytes)");
    }

    /**
     * For outputting how long the export took.
     * @see microtime()
     */
    public static function formatElapsed(float $elapsed): string
    {
        $m = floor($elapsed / 60);
        $s = $elapsed - $m * 60;
        return ($m) ? sprintf('%d:%05.2f', $m, $s) : sprintf('%05.2fs', $s);
    }

    /**
     * Human-readable filesize output.
     * @see memory_get_usage()
     */
    public static function formatBytes(int $size): string
    {
        if (!$size) {
            return '0b';
        }
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
        return @round($size / pow(1024, ($i = (int)floor(log($size, 1024)))), 1) . $unit[$i];
    }
}
