<?php

namespace Porter;

use ReflectionClass;

abstract class Package
{
    public const array INFO = [
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

    public const array TYPES = ['origins', 'sources', 'targets'];

    /**
     * If this is 'false', skip extract first post content from `Discussions.Body`.
     *
     * Do not change this default in child Sources.
     * Use `'hasDiscussionBody' => false` in FLAGS to declare your Source can skip this step.
     * @see Source::useDiscussionBody()
     * @see Source::disableDiscussionBody()
     */
    protected bool $hasDiscussionBody = true;

    protected bool $transferFiles = false;

    /** @var array */
    protected array $schemas = [];

    /** @var array<Component> */
    protected array $components = [];

    /** Main process. Run the MANIFEST methods if not overridden. */
    public function run(): void
    {
        $limit = (defined('PORTER_COMPONENTS')) ? PORTER_COMPONENTS : [];
        foreach (Support::list() as $step) {
            if (method_exists($this, $step)) { // @todo Check $this::FEATURE_REQUIREMENTS[$feature]['schema']
                // If PORTER_COMPONENTS was set (-c), ONLY run those steps and log steps that WOULD have run.
                if (!empty($limit) && !in_array($step, $limit) && 'setup' !== $step) { // Always run setup step.
                    Log::comment('Skipped step: ' . $step);
                    continue;
                }

                // Before running Target steps, check for the required Porter schema.
                if (method_exists($this, 'schemaExists') && false === $this->schemaExists($step)) {
                    Log::comment("Skipping import: $step (Source lacks support)");
                    continue;
                }

                // Do the step.
                $component = $this->$step();
                if (!empty($component)) { // Backwards compatibility.
                    $this->runComponent($component);
                }
            }
        }
    }

    public function runComponent(Component $component): void
    {
        throw new \LogicException('Not implemented');
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
        } elseif ('target' === $type && 'Vanilla' === $name) {
            // Hardcode Vanilla file support (all = yes).
            return [
                'features' => Support::list(),
                'required' => [],
                'info' => [
                    'name' => 'Vanilla (file)',
                    'avatarsPrefix' => 'p',
                    'avatarThumbnailsPrefix' => 'n',
                ],
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
        return $this->schemas[$name] ?? [];
    }

    protected function setSchema(string $name, array $schema): void
    {
        $this->schemas[$name] = $schema;
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
    public function useDiscussionBody(): bool
    {
        return $this->hasDiscussionBody;
    }

    /**
     * Set `useDiscussionBody` to false.
     */
    public function disableDiscussionBody(): void
    {
        $this->hasDiscussionBody = false;
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
