<?php

/**
 * Porter factories.
 */

use Porter\ConnectionManager;
use Porter\FileTransfer;
use Porter\Log;
use Porter\Migration;
use Porter\Origin;
use Porter\Postscript;
use Porter\Source;
use Porter\Target;
use Porter\Storage;

/**
 * @param Source $source
 * @param Target $target
 * @param string $outputName
 * @return FileTransfer
 * @throws Exception
 */
function fileTransferFactory(Source $source, Target $target, string $outputName): FileTransfer
{
    $porterStorage = new Storage\Database(new ConnectionManager($outputName, 'PORT_'));
    return new FileTransfer($source, $target, $porterStorage);
}

/**
 * Get valid origin class.
 *
 * @param string $origin
 * @return ?Origin
 */
function originFactory(string $origin): ?Origin
{
    $class = '\Porter\Origin\\' . ucwords($origin);
    if (!class_exists($class)) {
        Log::comment("No Source found for {$origin}");
    }

    return (class_exists($class)) ? new $class() : null;
}

/**
 * Get valid source class.
 *
 * @param string $source
 * @return ?Source
 */
function sourceFactory(string $source): ?Source
{
    $class = '\Porter\Source\\' . ucwords($source);
    if (!class_exists($class)) {
        Log::comment("No Source found for {$source}");
    }

    return (class_exists($class)) ? new $class() : null;
}

/**
 * Get valid target class.
 *
 * @param string $target
 * @return ?Target
 */
function targetFactory(string $target): ?Target
{
    if ('file' === $target) {
        return null;
    }

    $class = '\Porter\Target\\' . ucwords($target);
    if (!class_exists($class)) {
        Log::comment("No Target found for {$target}");
    }

    return (class_exists($class)) ? new $class() : null;
}

/**
 * Get postscript class if it exists.
 *
 * @param string $postscript
 * @return ?Postscript
 */
function postscriptFactory(string $postscript): ?Postscript
{
    $class = '\Porter\Postscript\\' . ucwords($postscript);
    if (!class_exists($class)) {
        Log::comment("No Postscript found for {$postscript}.");
    }

    return (class_exists($class)) ? new $class() : null;
}

/**
 * Setup a new migration.
 *
 * @param string $inputName
 * @param string $outputName
 * @param string $sourcePrefix
 * @param string $targetPrefix
 * @param string|null $limitTables
 * @return Migration
 * @throws Exception
 */
function migrationFactory(
    string $inputName,
    string $outputName,
    string $sourcePrefix = '',
    string $targetPrefix = '',
    ?string $limitTables = ''
): Migration {
    // @todo Delete $inputDB after Sources are all moved to $inputStorage.
    $inputDB = new \Porter\Database\DbFactory((new ConnectionManager($inputName))->connection()->getPDO());
    $inputStorage = new Storage\Database(new ConnectionManager($inputName, $sourcePrefix));
    if ($outputName === 'file') { // @todo storageFactory
        $porterStorage = new Storage\File(); // Only 1 valid 'file' type currently.
        $outputStorage = new Storage\File(); // @todo dead variable (halts at porter step)
        $postscriptStorage = new Storage\File(); // @todo dead variable (halts at porter step)
    } else {
        $porterStorage = new Storage\Database(new ConnectionManager($outputName, 'PORT_'));
        $outputStorage = new Storage\Database(new ConnectionManager($outputName, $targetPrefix));
        $postscriptStorage = new Storage\Database(new ConnectionManager($outputName, $targetPrefix));
    }
    $captureOnly = ($outputName === 'sql');
    return new Migration(
        $inputDB,
        $inputStorage,
        $porterStorage,
        $outputStorage,
        $postscriptStorage,
        loadStructure(),
        $limitTables,
        $captureOnly
    );
}
