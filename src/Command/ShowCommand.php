<?php

namespace Porter\Command;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Writer;
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
        if (!in_array($this->type, ['source', 'target'])) {
            (new Writer())->bold->yellow->write('Invalid value for <type>');
            return;
        }

        // Validate name.
        $info = ($this->type === 'source') ?  Support::getInstance()->getSources() :
            Support::getInstance()->getTargets();
        if (!array_key_exists($this->name, $info)) {
            (new Writer())->bold->yellow->write('Unknown package "' . $this->name . '" (case-sensitive).');
            return;
        }

        $this->showFeatures($this->type, $this->name, $info);
    }

    /**
     * Output feature table for a single platform.
     *
     * @param string $type
     * @param string $name
     * @param array $info
     */
    public function showFeatures(string $type, string $name, array $info): void
    {
        $writer = new Writer();
        $writer->bold->green->write("\n" . 'Support for ' . $type . ' ' . $info[$name]['name'] . "\n");
        $writer->table(Support::getInstance()->getFeatureTable($name, $info), ['head' => 'bold']);
    }
}
