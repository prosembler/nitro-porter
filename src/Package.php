<?php

namespace Porter;

abstract class Package
{
    public const SUPPORTED = [
        'name' => '',
        'defaultTablePrefix' => '',
        'charsetTable' => '', // Source-only
        'passwordHashMethod' => '',
        'avatarsPrefix' => '',
        'avatarThumbPrefix' => '',
        'avatarPath' => '',
        'avatarThumbPath' => '',
        'attachmentPath' => '',
        'attachmentThumbPath' => '',
        'features' => [],
    ];

    /** @var array Settings that change Target behavior. */
    protected const FLAGS = [];

    /** Main process. */
    abstract public function run(?Migration $port = null): void;

    /**
     * Get support info of the target package.
     *
     * @return array
     * @see Target::setSources()
     */
    public static function getSupport(): array
    {
        return static::SUPPORTED;
    }

    /**
     * Get name of the target package.
     *
     * @return string
     */
    public static function getName(): string
    {
        return static::SUPPORTED['name'];
    }

    /**
     * Get default table prefix of the target package.
     *
     * @return string
     */
    public static function getPrefix(): string
    {
        return static::SUPPORTED['defaultTablePrefix'];
    }

    /**
     * Retrieve characteristics of the package.
     *
     * @param string $name
     * @return mixed|null
     */
    public static function getFlag(string $name)
    {
        return (isset(static::FLAGS[$name])) ? static::FLAGS[$name] : null;
    }
}
