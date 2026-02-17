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
 * @param Storage\Https $input
 * @param Storage\Database $output
 * @return ?Origin
 */
function originFactory(string $origin, Storage\Https $input, Storage\Database $output): ?Origin
{
    $class = '\Porter\Origin\\' . ucwords($origin);
    if (!class_exists($class)) {
        Log::comment("No Source found for {$origin}");
    }

    return (class_exists($class)) ? new $class($origin, $input, $output) : null;
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
 * @throws Exception
 */
function storageFactory(string $name, ?string $prefix = ''): Storage
{
    if ($name === 'file') { // @todo storageFactory
        return new Storage\File();
    }
    return new Storage\Database(new ConnectionManager($name, $prefix));
}

/**
 * Setup a new migration.
 *
 * @param string $inputName
 * @param Storage $inputStorage
 * @param Storage $porterStorage
 * @param Storage $outputStorage
 * @param Storage $postscriptStorage
 * @param string|null $limitTables
 * @param bool $captureOnly
 * @return Migration
 * @throws Exception
 * @deprecated
 */
function migrationFactory(
    string $inputName,
    Storage $inputStorage,
    Storage $porterStorage,
    Storage $outputStorage,
    Storage $postscriptStorage,
    ?string $limitTables = '',
    bool $captureOnly = false
): Migration {
    // @todo Delete $inputDB after Sources are all moved to $inputStorage.
    $inputDB = new \Porter\Database\DbFactory((new ConnectionManager($inputName))->connection()->getPDO());
    return new Migration(
        $inputDB,
        $inputStorage,
        $porterStorage,
        $outputStorage,
        $postscriptStorage,
        loadData('structure'),
        $limitTables,
        $captureOnly
    );
}
