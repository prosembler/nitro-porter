<?php

/**
 * Porter factories.
 */

use Porter\ConnectionManager;
use Porter\FileTransfer;
use Porter\Log;
use Porter\Migration;
use Porter\Origin;
use Porter\Package;
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
 * Get Package if it exists.
 *
 * Uses sub-factories to more explicitly define return types.
 *
 * @param string $type
 * @param string $name
 * @param ?Storage $input
 * @param ?Storage $output
 * @return mixed|null
 */
function packageFactory(string $type, string $name, ?Storage $input, ?Storage $output): mixed
{
    if (!in_array($type, ['Origin', 'Source', 'Target', 'Postscript'])) {
        Log::comment("Invalid package type.");
    }
    $class = "\Porter\\" . $type . "\\" . ucwords($name);
    if (!class_exists($class)) {
        Log::comment("No {$type} package found for {$name}");
    }

    return (class_exists($class)) ? new $class($input, $output, $name) : null;
}

/**
 * Get Origin if it exists.
 *
 * @param string $origin
 * @param ?Storage\Https $originStorage
 * @param ?Storage\Database $inputStorage
 * @return ?Origin
 */
function originFactory(
    string $origin,
    ?Storage\Https $originStorage = null,
    ?Storage\Database $inputStorage = null
): ?Origin {
    return packageFactory('Origin', $origin, $originStorage, $inputStorage);
}

/**
 * Get Source if it exists.
 *
 * @param string $source
 * @param ?Storage $inputStorage
 * @param ?Storage $porterStorage
 * @return ?Source
 */
function sourceFactory(string $source, ?Storage $inputStorage = null, ?Storage $porterStorage = null): ?Source
{
    return packageFactory('Source', $source, $inputStorage, $porterStorage);
}

/**
 * Get Target if it exists.
 *
 * @param string $target
 * @param ?Storage $porterStorage
 * @param ?Storage $outputStorage
 * @return ?Target
 */
function targetFactory(string $target, ?Storage $porterStorage = null, ?Storage $outputStorage = null): ?Target
{
    return packageFactory('Target', $target, $porterStorage, $outputStorage);
}

/**
 * Get Postscript if it exists.
 *
 * @param string $postscript
 * @param Storage $outputStorage
 * @param Storage $postscriptStorage
 * @return ?Postscript
 */
function postscriptFactory(
    string $postscript,
    ?Storage $outputStorage = null,
    ?Storage $postscriptStorage = null
): ?Postscript {
    return packageFactory('Postscript', $postscript, $outputStorage, $postscriptStorage);
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
