<?php

namespace Porter;

use Exception;

class Factory
{
    /**
     * Setup a new FileTransfer service.
     */
    public static function fileTransfer(Source $source, Target $target, string $outputName): FileTransfer
    {
        $porterStorage = new Storage\Database(new PorterConnection($outputName, 'PORT_'));
        return new FileTransfer($source, $target, $porterStorage);
    }

    /**
     * Get Package if it exists.
     *
     * Uses sub-factories to more explicitly define return types.
     */
    protected static function package(string $type, string $name, ?Storage $input, ?Storage $output): mixed
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
    public static function origin(
        string $origin,
        ?Storage $input = null,
        ?Storage $extract = null,
        ?Storage $https = null,
    ): ?Origin {
        $origin = Factory::package('Origin', $origin, $input, $extract);
        if (is_a($https, Storage\Https::class)) {
            $origin->addHttps($https);
        }
        return $origin;
    }

    /**
     * Get Source if it exists.
     */
    public static function source(
        string $source,
        ?Storage $input = null,
        ?Storage $porter = null,
        string $dataTypes = '',
        string $inputName = ''
    ): ?Source {
        $source = Factory::package('Source', $source, $input, $porter);

        // Set constraints.
        $source->limitTables($dataTypes);

        // Add legacy database support to Sources.
        $inputDB = new \Porter\Database\DbFactory(new PorterConnection($inputName)->connection()->getPDO());
        $source->addLegacySupport($inputDB);

        return $source;
    }

    /**
     * Get Target if it exists.
     */
    public static function target(string $target, ?Storage $porter = null, ?Storage $output = null): ?Target
    {
        return Factory::package('Target', $target, $porter, $output);
    }

    /**
     * Get Postscript if it exists.
     */
    public static function postscript(string $psName, ?Storage $output = null, ?Storage $psStorage = null): ?Postscript
    {
        return Factory::package('Postscript', $psName, $output, $psStorage);
    }

    /**
     * @throws Exception
     */
    public static function storage(string $name, ?string $prefix = ''): Storage
    {
        if ($name === 'file') { // File storage has no connection.
            return new Storage\File();
        }

        // Connection info contains the type of storage we want to instantiate.
        $connection = new PorterConnection($name, $prefix);
        $storage = 'Storage\\' . ucfirst($connection->getType());
        return new $storage($connection);
    }
}
