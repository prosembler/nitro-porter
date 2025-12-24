<?php

namespace Porter;

class Support
{
    public const SUPPORTED_INFO = [
        'name',
        'defaultTablePrefix',
        'passwordHashMethod',
        'charsetTable',
        'avatarsPrefix',
        'avatarThumbnailsPrefix',
        'features',
    ];

    public const SUPPORTED_FEATURES = [
        'Users',
        'Categories',
        'Discussions',
        'Comments',
        'Roles',
        'Passwords',
        'PrivateMessages',
        'Attachments',
        'Bookmarks',
        'Avatars',
        'AvatarThumbnails',
        'Signatures',
        'Polls',
        'Tags',
        'Reactions',
        'Badges',
        'UserNotes',
        'Ranks',
        //'UserWall',
        //'Groups',
        //'Emoji',
        //'Articles',
    ];

    private static ?Support $instance = null;

    private array $sources = [];

    private array $targets = [];

    public static function getInstance(): self
    {
        if (self::$instance == null) {
            self::$instance = new Support();
        }

        return self::$instance;
    }

    /**
     * @return array
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * @return array
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    /**
     * Accepts the `SUPPORTED` array from each package to build a list.
     *
     * @param array $sources
     * @return void
     */
    public function setSources(array $sources): void
    {
        foreach ($sources as $name) {
            $classname = '\Porter\Source\\' . $name;
            if (is_a($classname, Source::class, true)) {
                $this->sources[$name] = $classname::getSupport();
            }
        }
    }

    /**
     * Accepts the `SUPPORTED` array from each package to build a list.
     *
     * @param array $targets
     * @return void
     */
    public function setTargets(array $targets): void
    {
        // Hardcode Vanilla file support (all = yes).
        $this->targets['file'] = [
            'name' => 'Vanilla (file)',
            'avatarsPrefix' => 'p',
            'avatarThumbnailsPrefix' => 'n',
            'features' => array_fill_keys(self::SUPPORTED_FEATURES, 1),
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
     * Get the data support status for a single platform feature.
     *
     * @param array $supported
     * @param string $package
     * @param string $feature
     * @param bool $notes
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
     * @param string $name
     * @param array $info
     * @return array
     */
    public function getFeatureTable(string $name, array $info): array
    {
        // Build feature list.
        $features = array_keys($this->getAllFeatures());
        $list = [];
        foreach ($features as $feature) {
            $list[] = [
                'feature' => preg_replace('/[A-Z]/', ' $0', $feature),
                'support' =>  $this->getFeatureStatus($info, $name, $feature)
            ];
        }
        return $list;
    }

    /**
     * Define what data can be successfully ported to Vanilla.
     *
     * First array key is where the data is stored.
     * Second array key is the feature name, and value is one of:
     *    - 0 if unsupported
     *    - 1 if supported
     *    - string if supported, with notes or caveats
     *
     * @return array
     */
    public function getAllFeatures(): array
    {
        return array_fill_keys(self::SUPPORTED_FEATURES, 0);
    }
}
