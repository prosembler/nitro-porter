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
    protected const FLAGS = [
        // Whether content/body is stored on the discussion/thread record. If both are true,
        // skip joins & renumbering keys since it's going to get undone by the target.
        'hasDiscussionBody' => false,
        // If both packages have file transfer support, they get synced up.
        'fileTransferSupport' => false,
        // Whether SOURCE keys are invalid ints (e.g. Discord SnowflakeIDs) — no effect for targets.
        'renumberIndices' => false,
    ];

    /** @var array|string[] Auto-run() this list of methods unless overwritten per-package. */
    protected const array MANIFEST = [
        // users
        'users', // inc. usermeta, signatures
        'roles',
        'badges',
        // taxonomy
        'categories',
        'groups',
        // content
        'discussions',
        'comments',
        'conversations',
        'wallposts', // public profile posts
        'usernotes', // private profile posts
        // meta
        'tags',
        'reactions',
        'bookmarks',
        'polls',
        // files
        'avatars',
        'attachments',
        'emoji',
    ];

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

    /** Main process. Run the MANIFEST methods if not overridden. */
    public function run(): void
    {
        foreach (self::MANIFEST as $step) {
            if (method_exists($this, $step)) {
                $this->$step();
            }
        }
    }

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
