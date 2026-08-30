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
        // Hardcode Vanilla file support (all = yes).
        $this->targets['file'] = [
            'name' => 'Vanilla (file)',
            'avatarsPrefix' => 'p',
            'avatarThumbnailsPrefix' => 'n',
            'features' => array_fill_keys($this->getFeatures(), 1),
        ];

        // Load the rest of the target support automatically.
        foreach ($targets as $name) {
            $classname = '\Porter\Target\\' . $name;
            if (is_a($classname, Target::class, true)) {
                $this->targets[$name] = $classname::getSupport();
            }
        }
    }

    /**
     * Use the manifest list, but without hooks
     */
    public function getFeatures(): array
    {
        $manifest = include(ROOT_DIR . '/manifest.php');
        return array_diff($manifest, self::MANIFEST_HOOKS);
    }

    /**
     * Get the data support status for a single platform feature.
     * @return string Yes or No.
     */
    public function getFeatureStatus(array $supported, string $package, string $feature, bool $notes = true): string
    {
        if (!isset($supported[$package]['features'])) {
            return 'No';
        }

        $available = $supported[$package]['features'];

        // Calculate feature availability.
        $status = '';
        if (isset($available[$feature])) {
            if ($available[$feature] === 0) {
                $status = 'No';
            } elseif ($available[$feature]) {
                // Say 'yes' for table shorthand
                $status = 'Yes';
                if ($notes && $available[$feature] !== 1) {
                    // Send the text of the note
                    $status = $available[$feature];
                }
            }
        }

        return $status;
    }

    /**
     * Build an array-based matrix of feature support.
     */
    public function getFeatureTable(string $name, array $info): array
    {
        // Build feature list.
        $features = $this->getFeatures();
        $list = [];
        foreach ($features as $feature) {
            $list[] = [
                'feature' => preg_replace('/[A-Z]/', ' $0', $feature),
                'support' =>  $this->getFeatureStatus($info, $name, $feature)
            ];
        }
        return $list;
    }
}
