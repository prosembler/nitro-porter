<?php

namespace Porter;

class Support
{
    public const array SUPPORTED_INFO = [
        'name',
        'defaultTablePrefix',
        'passwordHashMethod',
        'charsetTable',
        'avatarsPrefix',
        'avatarThumbnailsPrefix',
        'features',
    ];

    /**
     * @var array|string[] Manifest items that represent hooks, not features.
     * @todo Make this a declarative notation directly in the manifest.
     */
    public const array MANIFEST_HOOKS = [
        'setup',
        'filemap',
        'precontent',
        'cleanup',
    ];

    private static ?Support $instance = null;

    private array $origins = [];

    private array $sources = [];

    private array $targets = [];

    public static function getInstance(): self
    {
        if (self::$instance == null) {
            self::$instance = new Support();
        }
        return self::$instance;
    }

    public function getOrigins(): array
    {
        return $this->origins;
    }

    public function getSources(): array
    {
        return $this->sources;
    }

    public function getTargets(): array
    {
        return $this->targets;
    }

    /**
     * Retrieve an array from manifest.php.
     */
    public static function list(?string $name = null): array
    {
        return include(ROOT_DIR . '/manifest.php');
    }

    /**
     * Accepts the contents of packages.php.
     */
    public function set(array $packages): void
    {
        foreach (Package::TYPES as $type) {
            if (!empty($packages[$type])) {
                $method = 'set' . ucfirst($type);
                $this->$method($packages[$type]);
            }
        }
    }

    /** @see self::set() */
    private function setOrigins(array $origins): void
    {
        foreach ($origins as $name) {
            $classname = '\Porter\Origin\\' . $name;
            if (is_a($classname, Source::class, true)) {
                $this->origins[$name] = $classname::getSupport();
            }
        }
    }

    /** @see self::set() */
    private function setSources(array $sources): void
    {
        foreach ($sources as $name) {
            $classname = '\Porter\Source\\' . $name;
            if (is_a($classname, Source::class, true)) {
                $this->sources[$name] = $classname::getSupport();
            }
        }
    }

    /** @see self::set() */
    private function setTargets(array $targets): void
    {
        foreach ($targets as $name) {
            $classname = '\Porter\Target\\' . $name;
            if (is_a($classname, Target::class, true)) {
                $this->targets[$name] = $classname::getSupport();
            }
        }
    }

    /**
     * Use the manifest list, but without hooks.
     */
    public function getFeatures(): array
    {
        $manifest = include(ROOT_DIR . '/manifest.php');
        return array_diff($manifest, self::MANIFEST_HOOKS);
    }

    /**
     * Get the data support status for a single platform feature.
     * @return string 'Yes', 'No', or 'Requires X'.
     */
    public function getFeatureStatus(array $packageInfo, string $feature): string
    {
        $status = 'No';
        if (in_array($feature, $packageInfo['features'])) {
            $status = 'Yes';
            if (!empty($packageInfo['required'][$feature]['enabled'])) {
                $status = 'Requires ' . $packageInfo['required'][$feature]['enabled']; // Send the text of the note
            }
        }
        return $status;
    }

    /**
     * Build an array-based matrix of feature support.
     */
    public function getFeatureTable(array $packageInfo): array
    {
        foreach ($this->getFeatures() as $feature) {
            $list[] = [
                'feature' => preg_replace('/[A-Z]/', ' $0', $feature),
                'support' =>  $this->getFeatureStatus($packageInfo, $feature),
            ];
        }
        return $list ?? [];
    }
}
