<?php

namespace Porter\Command;

use Ahc\Cli\Input\Command;
use Ahc\Cli\IO\Interactor;
use Porter\Config;
use Porter\Request;

class RunCommand extends Command
{
    public function __construct()
    {
        parent::__construct('run', 'Run a migration.');
        $this
            ->option('-s --source', 'Source package alias')
            ->option('-t --target', 'Target package alias')
            ->option('-i --input', 'Source connection alias (defined in config)')
            ->option('-o --output', 'Target connection alias (defined in config), "file", or "sql"')
            ->option('--sp', 'Source table prefix (override package default)')
            ->option('--tp', 'Target table prefix (override package default)')
            ->option('--cdn', 'CDN prefix')
            ->option('-d --data', 'Limit to specified data types (CSV)')
            ->usage(
                '<bold>  run -s xenforo -t flarum -i xf25 -o test --sp xf_ </end><eol/>' .
                    '<comment>  Migrate from Xenforo in database with alias `xf25` (in config.php) ' .
                    'using table prefix `xf_`<eol/>  to Flarum in database with alias `test` ' .
                    'using the default table prefix (because --tp is omitted).</end><eol/>'
            );
    }

    /**
     * Prompts for the user to collect required information.
     */
    public function interact(Interactor $io): void
    {
        if (!$this->source && !Config::getInstance()->get('source')) {
            $this->set('source', $io->prompt('Source package alias (see `porter list -n=sources`)'));
        }

        if (!$this->target && !Config::getInstance()->get('target')) {
            $this->set('target', $io->prompt('Target package alias (see `porter list -n=targets`)'));
        }

        if (!$this->input && !Config::getInstance()->get('input_alias')) {
            $this->set('input', $io->prompt('Input connection alias (see config.php)'));
        }

        if (!$this->output && $this->source !== 'file' && !Config::getInstance()->get('output_alias')) {
            $this->set('output', $io->prompt('Output connection alias (see config.php)'));
        }
    }

    /**
     * Command execution.
     *
     * @throws \Exception
     */
    public function execute(): void
    {
        $request = new Request(
            $this->source,
            $this->target,
            $this->input,
            $this->output,
            $this->sp,
            $this->tp,
            $this->cdn,
            $this->data
        );

        runPorter($request);
    }
}
