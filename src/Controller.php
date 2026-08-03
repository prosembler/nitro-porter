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
     * Export workflow (Source -> `PORT_`).
     */
    protected function doExport(Source $source, bool $captureOnly = false): void
    {
        $source->verifySource($source->sourceTables);
        if (!defined('PORTER_INPUT_ENCODING')) {
            define('PORTER_INPUT_ENCODING', $source->getInputEncoding($source::getCharsetTable()));
        }

        if (!$captureOnly) {
            $source->porterStorage->begin();
        }

        $source->run();
        if (method_exists($source, 'validate')) {
            $source->validate(); // New; no need for $port & not required via abstract for bc.
        }
        $source->porterStorage->end();
    }

    /**
     * Import workflow (`PORT_` -> Target).
     */
    protected function doImport(?Target $target): void
    {
        // Nothing to do if there's no Target.
        if (empty($target)) {
            return;
        }
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
    protected function doPostscript(?Postscript $postscript): void
    {
        // Nothing to do if there's no Postscript.
        if (empty($postscript)) {
            return;
        }
        $postscript->run();
    }

    /**
     * Transfer files if supported.
     */
    protected function doFileTransfer(FileTransfer $fileTransfer): void
    {
        if (!$fileTransfer->isSupported()) {
            return;
        }
        $fileTransfer->run();
    }

    /**
     * Do some intelligent configuration of the migration process.
     *
     * This is the ONLY opportunity for the source & target to "coordinate."
     *
     * @param Source $source
     * @param ?Target $target
     */
    protected function setFlags(Source $source, ?Target $target): void
    {
        // Nothing to negotiate if there's no Target.
        if (empty($target)) {
            return;
        }

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

        // Collect request.
        $sourceName = $request->getSource();
        $targetName = $request->getTarget();
        $inputName = $request->getInput();
        $outputName = $request->getOutput();
        $porterName = $request->getPorter();
        $sourcePrefix = $request->getInputTablePrefix();
        $targetPrefix = $request->getOutputTablePrefix();
        $dataTypes = $request->getDatatypes();

        // Log request.
        Log::comment("NITRO PORTER RUNNING...");
        Log::comment("Porting " . $sourceName . " to " . $targetName);
        Log::comment("Input: " . $inputName . ' (' . (empty($sourcePrefix) ? 'no prefix' : $sourcePrefix) . ')');
        Log::comment("Porter: " . $porterName . ' (PORT_)');
        Log::comment("Output: " . $outputName . ' (' . (empty($targetPrefix) ? 'no prefix' : $targetPrefix) . ')');
        Log::comment("\n" . sprintf('[ STARTED at %s ]', date('H:i:s e')) . "\n");

        // Build artifacts.
        $inputStorage = Factory::storage($inputName, $sourcePrefix);
        $porterStorage = Factory::storage($porterName, 'PORT_');
        $outputStorage = Factory::storage($outputName, $targetPrefix);
        $postscriptStorage = Factory::storage($outputName, $targetPrefix); // Postscript names must match target names.
        $source = Factory::source($sourceName, $inputStorage, $porterStorage, $dataTypes, $inputName);
        $target = Factory::target($targetName, $porterStorage, $outputStorage);
        $postscript = Factory::postscript($targetName, $outputStorage, $postscriptStorage);
        $fileTransfer = Factory::fileTransfer($source, $target, $porterName);

        // Main workflow.
        $this->setFlags($source, $target);
        $this->doExport($source, ($outputName === 'sql'));
        $this->doImport($target);
        $this->doPostscript($postscript);
        $this->doFileTransfer($fileTransfer);

        // Report finished.
        Log::comment("\n" . sprintf(
            '[ FINISHED at %s after running for %s ]',
            date('H:i:s e'),
            Log::formatElapsed(microtime(true) - $start)
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
        $inputStorage = Factory::storage($inputName);
        $extractStorage = Factory::storage($inputName);
        $originStorage = Factory::storage($originName);
        $origin = Factory::origin($originName, $inputStorage, $extractStorage, $originStorage);

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
            Log::formatElapsed(microtime(true) - $start)
        ));
    }
}
