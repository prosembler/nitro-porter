<?php

namespace Porter;

use ReflectionClass;

abstract class Package
{
    public const INFO = [
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
    ];

    /** @var array Declare requirements for each feature to run. */
    public const array FEATURE_REQUIREMENTS = [
        //$featureName => ['enabled' => 'some/plugin','schema' => [$table => [$columns]],],
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

    public const TYPES = ['origins', 'sources', 'targets'];

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

    /** @var array */
    protected array $schema = [];

    /** Main process. Run the MANIFEST methods if not overridden. */
    public function run(): void
    {
        foreach (Support::list() as $step) { // @todo Add to packages via Factory::package().
            if (method_exists($this, $step)) { // @todo Check $this::FEATURE_REQUIREMENTS[$feature]['schema']
                $this->$step();
            }
        }
    }

    /**
     * Retrieve an array from packages.php.
     */
    public static function list(?string $name = null): array
    {
        $packages = include(ROOT_DIR . '/packages.php');
        if (!empty($name) && in_array($name, Package::TYPES, true)) {
            return $packages[$name] ?? [];
        } else {
            return $packages;
        }
    }

    /** Retrieve metadata from the Package. */
    public static function inspect(string $type, string $name): array
    {
        $class = '\Porter' . '\\' . ucfirst($type) . '\\' . $name;
        if (class_exists($class, false)) {
            $methods = new ReflectionClass(new $class())->getMethods();
            $methods = array_column($methods, 'name');

            if (defined($class . '::FEATURE_REQUIREMENTS')) {
                $required = $class::FEATURE_REQUIREMENTS;
            } else {
                $required = array_filter($class::SUPPORTED['features'] ?? [], function ($item) {
                    return !is_numeric($item);
                });
            }

            return [
                'features' => array_intersect($methods, Support::list()),
                'required' => $required,
                'info' => $class::INFO,
            ];
        }
        return [];
    }

    /**
     * Get support info of the target package.
     * @see Target::setSources()
     */
    public static function getSupport(): array
    {
        return static::INFO;
    }

    protected function getSchema(string $name): array
    {
        return $this->schema[$name] ?? [];
    }

    /**
     * Get name of the target package.
     */
    public static function getName(): string
    {
        return static::INFO['name'];
    }

    /**
     * Get default table prefix of the target package.
     */
    public static function getPrefix(): string
    {
        return static::INFO['defaultTablePrefix'];
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
