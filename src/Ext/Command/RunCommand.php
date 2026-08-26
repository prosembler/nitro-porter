<?php

namespace Porter\Ext\Command;

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
            ->option('-i --inputstore', 'Source storage alias (defined in config)')
            ->option('-o --outputstore', 'Target storage alias (defined in config), "file", or "sql"')
            ->option('-p --porterstore', 'Porter storage alias (defaults to output storage)')
            ->option('--srcpre', 'Source table prefix (override package default)')
            ->option('--tarpre', 'Target table prefix (override package default)')
            ->option('--cdnpre', 'CDN file path prefix')
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
            $this->set('source', $io->prompt('Source package alias (see `porter list sources`)'));
        }

        if (!$this->target && !Config::getInstance()->get('target')) {
            $this->set('target', $io->prompt('Target package alias (see `porter list targets`)'));
        }

        if (!$this->inputstore && !Config::getInstance()->get('input_alias')) {
            $this->set('inputstore', $io->prompt('Input storage alias (see config.php)'));
        }

        if (!$this->outputstore && $this->source !== 'file' && !Config::getInstance()->get('output_alias')) {
            $this->set('outputstore', $io->prompt('Output storage alias (see config.php)'));
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
            sourcePackage: $this->source,
            targetPackage: $this->target,
            inputStorage: $this->inputstore,
            outputStorage: $this->outputstore,
            porterStorage: $this->porterstore,
            inputTablePrefix: $this->sp,
            outputTablePrefix: $this->tp,
            cdnPrefix: $this->cdn,
            dataTypes: $this->data,
        ));

        (new \Porter\Controller())->run($request);
    }
}
