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
        // prepare
        'setup', // HOOK
        'filemap', // Map a file transfer.
        // users
        'users',
        'roles',
        'badges',
        'ranks',
        'usermeta',
        'signatures',
        // taxonomy
        'categories',
        'groups',
        // content
        'precontent', // HOOK
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
        'emojis',
        // finalize
        'cleanup', // HOOK
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
     * Retrieve an array from named file in `/data`.
     */
    public static function list(string $name): array
    {
        $data = ['origins', 'sources', 'targets'];
        if (in_array($name, $data, true)) {
            $packages = include(ROOT_DIR . '/packages.php');
            return $packages[$name] ?? [];
        } else {
            return [];
        }
    }

    /**
     * Get support info of the target package.
     * @see Target::setSources()
     */
    public static function getSupport(): array
    {
        return static::SUPPORTED;
    }

    /**
     * Get name of the target package.
     */
    public static function getName(): string
    {
        return static::SUPPORTED['name'];
    }

    /**
     * Get default table prefix of the target package.
     */
    public static function getPrefix(): string
    {
        return static::SUPPORTED['defaultTablePrefix'];
    }

    /**
     * Retrieve characteristics of the package.
     */
    public static function getFlag(string $name): mixed
    {
        return (isset(static::FLAGS[$name])) ? static::FLAGS[$name] : null;
    }

    /**
     * Whether to connect the OP to the discussion record.
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
