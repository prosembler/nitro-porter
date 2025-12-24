<?php

namespace Porter\Command;

use Ahc\Cli\Input\Command;
use Ahc\Cli\IO\Interactor;
use Ahc\Cli\Output\Writer;
use Porter\Support;

class ListCommand extends Command
{
    public function __construct()
    {
        parent::__construct('list', 'List available packages of requested type.');
        $this
            ->argument('<type>', 'One of "sources", "targets", or "connections"')
            ->usage(
                '<bold>  list</end> <comment>sources</end> ## List all available Source packages.<eol/>' .
                '<bold>  list</end> <comment>targets</end> ## List all available Target packages.<eol/>' .
                '<bold>  list</end> <comment>connections</end> ## List all Connections in config.<eol/>'
            );
    }

    /**
     * Prompts for the user to collect required information.
     */
    public function interact(Interactor $io): void
    {
        if (!$this->type) {
            $lists = ['s' => 'sources', 't' => 'targets', 'c' => 'connections'];
            $choice = $io->choice('Select a list', $lists, '3');
            $this->set('type', $lists[$choice]);
        }
    }

    /**
     * Command execution.
     */
    public function execute(): void
    {
        switch ($this->type) {
            case 'sources':
            case 'targets':
                $this->listSupport($this->type);
                break;
            case 'connections':
                $this->viewConnections();
                break;
            default:
                (new Writer())->bold->yellow->write('Invalid value for <name>');
        }
    }

    /**
     * Output a list of connections to shell.
     */
    public function viewConnections(): void
    {
        $writer = new Writer();
        $writer->bold->green->write("\n" . 'Supported Connections' . "\n");
        $writer->comment('List is from config.php' . "\n");
        foreach (\Porter\Config::getInstance()->getConnections() as $c) {
            $writer->write("\n");
            $writer->bold->write($c['alias']);
            $writer->write(' (' . $c['user'] . '@' . $c['name'] . ')');
        }
        $writer->write("\n\n");
    }

    /**
     * Output a list of supported platforms.
     *
     * @param string $type One of 'sources' or 'targets'.
     */
    public function listSupport(string $type): void
    {
        // Get the list.
        $info = ($type === 'sources') ?  Support::getInstance()->getSources() :
            Support::getInstance()->getTargets();
        $packages = array_keys($info);

        // Output
        $writer = new Writer();
        $writer->bold->green->write("\n" . 'Supported ' . ucfirst($type) . "\n");
        foreach ($packages as $package) {
            $writer->write("\n");
            $writer->bold->write($package);
            $writer->write(' (' . $info[$package]['name'] . ')');
        }
        $writer->write("\n\n");
    }
}
