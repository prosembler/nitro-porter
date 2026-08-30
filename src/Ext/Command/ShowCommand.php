<?php

namespace Porter\Ext\Command;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Writer;
use Porter\Package;
use Porter\Support;

class ShowCommand extends Command
{
    public function __construct()
    {
        parent::__construct('show', 'Show feature support of a package.');
        $this
            ->argument('<type>', 'One of "source" or "target"')
            ->argument('<name>', 'Name of package.')
            ->usage(
                '<bold>  show</end> <comment>source Vanilla</end> ' .
                    '## Show what features can be migrated from Vanilla.<eol/>' .
                '<bold>  show</end> <comment>target Flarum</end> ' .
                    '## Show what features can be migrated to Flarum.<eol/>'
            );
    }

    /**
     * Command execution.
     */
    public function execute(): void
    {
        // Validate type.
        $pluralType = $this->type . 's';
        if (!in_array($pluralType, Package::TYPES)) {
            (new Writer())->bold->yellow->write('Invalid value for <type>');
            return;
        }

        // Validate name.
        if (!in_array($this->name, Package::list($pluralType))) {
            (new Writer())->bold->yellow->write('Unknown package "' . $this->name . '" (case-sensitive).');
            return;
        }

        $this->showFeatures($this->type, $this->name);
    }

    /**
     * Output feature table for a single platform.
     *
     * @param string $type
     * @param string $name
     * @param array $packageInfo
     */
    public function showFeatures(string $type, string $name): void
    {
        $writer = new Writer();
        $packageInfo = Package::inspect($type, $name);
        $writer->bold->green->write("\n" . 'Support for ' . $type . ' ' . $packageInfo['info']['name'] . "\n");
        $writer->table(Support::getInstance()->getFeatureTable($packageInfo), ['head' => 'bold']);
    }
}
