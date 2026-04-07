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
     * @var bool Whether to capture SQL without executing.
     */
    public bool $captureOnly = false;

    /**
     * Export workflow.
     */
    protected function doExport(Source $source): void
    {
        $source->verifySource($source->sourceTables);
        if (!defined('PORTER_INPUT_ENCODING')) {
            define('PORTER_INPUT_ENCODING', $source->getInputEncoding($source::getCharsetTable()));
        }

        if (!$this->captureOnly) {
            $source->porterStorage->begin();
        }

        $source->run();
        if (method_exists($source, 'validate')) {
            $source->validate(); // New; no need for $port & not required via abstract for bc.
        }
        $source->porterStorage->end();
    }

    /**
     * Import workflow.
     */
    protected function doImport(Target $target): void
    {
        $target->outputStorage->begin();
        $target->validate();
        $target->run();
        $target->outputStorage->end();
    }

    /**
     * Finalize the import (if the optional postscript class exists).
     *
     * Use a separate database connection since re-querying data may be necessary.
     *    -> "Cannot execute queries while other unbuffered queries are active."
     */
    protected function doPostscript(Postscript $postscript): void
    {
        $postscript->run();
    }

    /**
     * Do some intelligent configuration of the migration process.
     *
     * This is the ONLY opportunity for the source & target to "coordinate."
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
        Log::comment("? 'Use Discussion Body' = " . ($target->getDiscussionBodyMode() ? 'On' : 'Off'));

        // Evaluate if both packages have file transfer support and sync them.
        if (
            $source::getFlag('fileTransferSupport') === true &&
            $target::getFlag('fileTransferSupport') === true
        ) {
            $source->enableFileTransfer();
            $target->enableFileTransfer();
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
        $start = microtime(true); // Start the timer.
        set_time_limit(0); // Disable PHP time limit.
        ini_set('memory_limit', '256M'); // Override memory limit to be high enough.

        // Break down the Request.
        $sourceName = $request->getSource();
        $targetName = $request->getTarget();
        $inputName = $request->getInput();
        $outputName = $request->getOutput();
        $sourcePrefix = $request->getInputTablePrefix();
        $targetPrefix = $request->getOutputTablePrefix();
        $dataTypes = $request->getDatatypes();

        // Log the Request.
        Log::comment("NITRO PORTER RUNNING...");
        Log::comment("Porting " . $sourceName . " to " . $targetName);
        Log::comment("Input: " . $inputName . ' (' . ($sourcePrefix ?? 'no prefix') . ')');
        Log::comment("Porter: " . $outputName . ' (PORT_)');
        Log::comment("Output: " . $outputName . ' (' . ($targetPrefix ?? 'no prefix') . ')');
        Log::comment("\n" . sprintf('[ STARTED at %s ]', date('H:i:s e')) . "\n");

        // Factory for migration artifacts.
        $inputStorage = storageFactory($inputName, $sourcePrefix);
        $porterStorage = storageFactory($outputName, 'PORT_');
        $outputStorage = storageFactory($outputName, $targetPrefix);
        $postscriptStorage = storageFactory($outputName, $targetPrefix); // Postscript names must match target names.
        $source = sourceFactory($sourceName, $inputStorage, $porterStorage);
        $target = targetFactory($targetName, $porterStorage, $outputStorage);
        $postscript = postscriptFactory($targetName, $outputStorage, $postscriptStorage);
        $fileTransfer = fileTransferFactory($source, $target, $outputName);

        // Set constraints.
        $source->limitTables($dataTypes);
        $this->captureOnly = ($outputName === 'sql');

        // Add legacy database support to Sources.
        $inputDB = new \Porter\Database\DbFactory(new ConnectionManager($inputName)->connection()->getPDO());
        $source->addLegacySupport($inputDB);

        // Evaluate the Source & Target flags.
        if ($target) {
            $this->setFlags($source, $target);
        }

        // Export (Source -> `PORT_`).
        $this->doExport($source);

        // Import (`PORT_` -> Target).
        if ($target) {
            $this->doImport($target);
            if ($postscript) {
                $this->doPostscript($postscript);
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
        $extractStorage = new Storage\Database(new ConnectionManager($inputName));
        $origin = originFactory($originName, $inputStorage, $extractStorage);

        $originStorage = new Storage\Https(new ConnectionManager($originName)); // @todo non-API origins
        $origin->addHttps($originStorage);

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
        $origin->run();

        // Report finished.
        Log::comment("\n" . sprintf(
            '[ FINISHED at %s after running for %s ]',
            date('H:i:s e'),
            formatElapsed(microtime(true) - $start)
        ));
    }
}
