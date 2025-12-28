<?php

namespace Porter\Command;

use Ahc\Cli\Input\Command;
use Ahc\Cli\IO\Interactor;
use Porter\Config;
use Porter\Request;

class PullCommand extends Command
{
    public function __construct()
    {
        parent::__construct('pull', 'Pull data from origin.');
        $this
            ->option('-r --origin', 'Origin package alias')
            ->option('-i --input', 'Target connection alias (defined in config)')
            ->usage(
                '<bold>  pull -s discord -i myserver </end><eol/>' .
                '<comment>  Pull data from Discord into database with alias `myserver` (in config.php). ' .
                'While it is the output of this step, we use --input per the overall migration process.</end><eol/>'
            );
    }

    /**
     * Prompts for the user to collect required information.
     */
    public function interact(Interactor $io): void
    {
        if (!$this->source && !Config::getInstance()->get('origin_alias')) {
            $this->set('origin', $io->prompt('Origin package alias (see `porter list origins`)'));
        }

        if (!$this->input && !Config::getInstance()->get('input_alias')) {
            $this->set('input', $io->prompt('Input connection alias (see config.php)'));
        }
    }

    /**
     * Command execution.
     *
     * @throws \Exception
     */
    public function execute(): void
    {
        $request = (new Request(
            originPackage: $this->origin,
            inputConnection: $this->input,
        ));

        (new \Porter\Controller())->pull($request);
    }
}
