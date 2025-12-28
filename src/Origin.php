<?php

namespace Porter;

abstract class Origin
{
    public const SUPPORTED = [
        'name' => '',
    ];

    abstract public function run(Storage\Https $input, Storage\Database $output): void;

    /**
     * Get name of the source package.
     *
     * @return array
     * @see Support::setSources()
     */
    public static function getSupport(): array
    {
        return static::SUPPORTED;
    }

    /**
     * Get name of the source package.
     *
     * @return string
     */
    public static function getName(): string
    {
        return static::SUPPORTED['name'];
    }
}
