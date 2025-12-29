<?php

/**
 *
 */

namespace Porter;

/**
 * Top-level workflows.
 */
class Controller
{
    /**
     * Export workflow.
     *
     * @param Source $source
     * @param Migration $port
     */
    protected function doExport(Source $source, Migration $port): void
    {
        $port->verifySource($source->sourceTables);
        if (!defined('PORTER_INPUT_ENCODING')) {
            define('PORTER_INPUT_ENCODING', $port->getInputEncoding($source::getCharsetTable()));
        }

        $port->begin();
        $source->run($port);
        $port->end();
    }

    /**
     * Import workflow.
     *
     * @param Target $target
     * @param Migration $port
     */
    protected function doImport(Target $target, Migration $port): void
    {
        $port->begin();
        $target->validate($port);
        $target->run($port);
        $port->end();
    }

    /**
     * Finalize the import (if the optional postscript class exists).
     *
     * Use a separate database connection since re-querying data may be necessary.
     *    -> "Cannot execute queries while other unbuffered queries are active."
     *
     * @param Postscript $postscript
     * @param Migration $port
     */
    protected function doPostscript(Postscript $postscript, Migration $port): void
    {
        $postscript->run($port);
    }

    /**
     * Do some intelligent configuration of the migration process.
     *
     * @param Source $source
     * @param Target $target
     */
    protected function setFlags(Source $source, Target $target): void
    {
        // If both the source and target don't store content/body on the discussion/thread record,
        // skip the conversion on both sides so we don't do joins and renumber keys for nothing.
        if (
            $source::getFlag('hasDiscussionBody') === false &&
            $target::getFlag('hasDiscussionBody') === false
        ) {
            $source->skipDiscussionBody();
            $target->skipDiscussionBody();
        }
    }

    /**
     * Setup & run the requested migration process.
     *
     * Translates `Request` into action (i.e. `Request` object should not pass beyond here).
     * @throws \Exception
     */
    public function run(Request $request): void
    {
        // Break down the Request.
        $sourceName = $request->getSource();
        $targetName = $request->getTarget();
        $inputName = $request->getInput();
        $outputName = $request->getOutput();
        $sourcePrefix = $request->getInputTablePrefix();
        $targetPrefix = $request->getOutputTablePrefix();
        $dataTypes = $request->getDatatypes();

        // Create new migration artifacts.
        $port = migrationFactory($inputName, $outputName, $sourcePrefix, $targetPrefix, $dataTypes);
        $source = sourceFactory($sourceName);
        $target = targetFactory($targetName);
        $postscript = postscriptFactory($targetName);
        $fileTransfer = fileTransferFactory($source, $target, $outputName);

        // Report on request.
        Log::comment("NITRO PORTER RUNNING...");
        Log::comment("Porting " . $sourceName . " to " . $targetName);
        Log::comment("Input: " . $inputName . ' (' . ($sourcePrefix ?? 'no prefix') . ')');
        Log::comment("Porter: " . $outputName . ' (PORT_)');
        Log::comment("Output: " . $outputName . ' (' . ($targetPrefix ?? 'no prefix') . ')');

        // Setup & log flags.
        if ($target) {
            $this->setFlags($source, $target);
            Log::comment("? 'Use Discussion Body' = " .
                ($target->getDiscussionBodyMode() ? 'Enabled' : 'Disabled'));
        }
        set_time_limit(0);
        ini_set('memory_limit', '256M');

        // Report start.
        $start = microtime(true);
        Log::comment("\n" . sprintf(
            '[ STARTED at %s ]',
            date('H:i:s e')
        ) . "\n");

        // Export (Source -> `PORT_`).
        $this->doExport($source, $port);

        // Import (`PORT_` -> Target).
        if ($target) {
            $this->doImport($target, $port);
            // Postscript names must match target names currently.
            if ($postscript) {
                $this->doPostscript($postscript, $port);
            }
        }

        // File transfer.
        if ($fileTransfer->isSupported()) {
            Log::comment('File Transfer started...');
            $fileTransfer->run();
            Log::comment('File Transfer completed.');
        }

        // Report finished.
        Log::comment("\n" . sprintf(
            '[ FINISHED at %s after running for %s ]',
            date('H:i:s e'),
            formatElapsed(microtime(true) - $start)
        ));
        Log::comment("[ After testing, you may delete any `PORT_` database tables. ]");
        Log::comment('[ Porter never migrates user permissions! Reset user permissions afterward. ]' . "\n\n");
    }

    /**
     * Data pull from origin workflow.
     *
     * @param Request $request
     * @throws \Exception
     */
    public function pull(Request $request): void
    {
        // Break down the Request.
        $originName = $request->getOrigin();
        $inputName = $request->getInput();

        // Create new migration artifacts.
        $inputStorage = new Storage\Database(new ConnectionManager($inputName));
        $originStorage = new Storage\Https(new ConnectionManager($originName)); // @todo non-API origins
        $origin = originFactory($originName, $inputStorage, $originStorage);

        // Report on request.
        Log::comment("NITRO PORTER PULLING...");
        Log::comment("Pulling " . $originName . " into " . $inputName);

        // Setup.
        set_time_limit(0);

        // Report start.
        $start = microtime(true);
        Log::comment("\n" . sprintf(
            '[ STARTED at %s ]',
            date('H:i:s e')
        ) . "\n");

        // Do the pull.
        $origin->run(null);

        // Report finished.
        Log::comment("\n" . sprintf(
            '[ FINISHED at %s after running for %s ]',
            date('H:i:s e'),
            formatElapsed(microtime(true) - $start)
        ));
    }
}
