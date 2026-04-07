<?php

/**
 * Porter factories.
 */

use Porter\ConnectionManager;
use Porter\FileTransfer;
use Porter\Log;
use Porter\Origin;
use Porter\Package;
use Porter\Postscript;
use Porter\Source;
use Porter\Target;
use Porter\Storage;

/**
 * Setup a new FileTransfer service.
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
 */
function originFactory(string $origin, ?Storage $inputStorage = null, ?Storage $extractStorage = null): ?Origin
{
    return packageFactory('Origin', $origin, $inputStorage, $extractStorage);
}

/**
 * Get Source if it exists.
 */
function sourceFactory(string $source, ?Storage $inputStorage = null, ?Storage $porterStorage = null): ?Source
{
    return packageFactory('Source', $source, $inputStorage, $porterStorage);
}

/**
 * Get Target if it exists.
 */
function targetFactory(string $target, ?Storage $porterStorage = null, ?Storage $outputStorage = null): ?Target
{
    return packageFactory('Target', $target, $porterStorage, $outputStorage);
}

/**
 * Get Postscript if it exists.
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
