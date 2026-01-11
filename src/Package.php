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

    protected bool $transferFiles = false;

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
     * Whether to attempt a file transfer.
     *
     * @return bool
     */
    public function getFileTransferSupport(): bool
    {
        return $this->transferFiles;
    }

    /**
     * Set `transferFiles` to true.
     */
    public function enableFileTransfer(): void
    {
        $this->transferFiles = true;
    }
}
