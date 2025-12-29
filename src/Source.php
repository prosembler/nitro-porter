<?php

namespace Porter;

abstract class Source extends Package
{
    public const SUPPORTED = [
        'name' => '',
        'defaultTablePrefix' => '',
        'charsetTable' => '',
        'passwordHashMethod' => '',
        'avatarsPrefix' => '',
        'avatarThumbPrefix' => '',
        'avatarPath' => '',
        'avatarThumbPath' => '',
        'attachmentPath' => '',
        'attachmentThumbPath' => '',
        'features' => [],
    ];

    /** @var array Settings that change Source behavior. */
    protected const FLAGS = [];

    /**
     * If this is 'false', skip extract first post content from `Discussions.Body`.
     *
     * Do not change this default in child Sources.
     * Use `'hasDiscussionBody' => false` in FLAGS to declare your Source can skip this step.
     *
     * @var bool
     * @see Source::getDiscussionBodyMode()
     * @see Source::skipDiscussionBody()
     */
    protected bool $useDiscussionBody = true;

    /**
     * @var array Required tables, columns set per exporter
     */
    public array $sourceTables = [];

    /**
     * Forum-specific export routine
     */
    abstract public function run(Migration $port): void;

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

    /**
     * Get default table prefix of the source package.
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
     * @return mixed
     */
    public static function getFlag(string $name): mixed
    {
        return (isset(static::FLAGS[$name])) ? static::FLAGS[$name] : null;
    }

    /**
     * Whether to connect the OP to the discussion record.
     *
     * @return bool
     */
    public function getDiscussionBodyMode(): bool
    {
        return $this->useDiscussionBody;
    }

    /**
     * Set `useDiscussionBody` to false.
     */
    public function skipDiscussionBody(): void
    {
        $this->useDiscussionBody = false;
    }

    /**
     * @return string
     */
    public static function getCharsetTable(): string
    {
        $charset = '';
        if (isset(static::SUPPORTED['charsetTable'])) {
            $charset = static::SUPPORTED['charsetTable'];
        }
        return $charset;
    }

    /**
     * Return the requested path (without a trailing slash).
     *
     * @param string $type
     * @param bool $addFullPath
     * @return string
     */
    public function getPath(string $type, bool $addFullPath = false): string
    {
        $folder = rtrim(static::SUPPORTED[$type . 'Path'] ?? '', '/');
        if ($addFullPath && Config::getInstance()->get('source_root')) {
            $folder = rtrim(Config::getInstance()->get('source_root'), '/') . '/' . trim($folder, '/');
        }
        return $folder;
    }
}
