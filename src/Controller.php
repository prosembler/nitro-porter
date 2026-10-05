<?php

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
        if (empty($target)) {
            return; // Nothing to do if there's no Target.
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
        if (empty($postscript)) {
            return; // Nothing to do if there's no Postscript.
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
     */
    protected function setFlags(Source $source, ?Target $target): void
    {
        if (empty($target)) {
            return; // Nothing to negotiate if there's no Target.
        }

        // If both the source and target don't store content/body on the discussion/thread record,
        // skip the conversion on both sides so we don't do joins and renumber keys for nothing.
        if (
            $source::getFlag('hasDiscussionBody') === false &&
            $target::getFlag('hasDiscussionBody') === false
        ) {
            $source->disableDiscussionBody();
            $target->disableDiscussionBody();
        }
        $explainer = 'Off: Left empty to optimize performance';
        if ($target->useDiscussionBody()) {
            $explainer = 'On: Used by ' . (($source::getFlag('hasDiscussionBody')) ?
                (($target::getFlag('hasDiscussionBody')) ? 'both packages' :
                    'Source only (expect slower comments import)') :
                    'Target only (expect slower comments export)');
        }
        Log::comment("? PORT_Discussion.Body = " . $explainer . "\n");

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
        // Collect request.
        $sourceName = $request->getSource();
        $targetName = $request->getTarget();
        $inputName = $request->getInput();
        $outputName = $request->getOutput();
        $porterName = $request->getPorter();
        $sourcePrefix = $request->getInputTablePrefix();
        $targetPrefix = $request->getOutputTablePrefix();

        // Report request.
        Log::comment("NITRO PORTER STARTED at " . date('H:i:s e'));
        Log::comment("Porting from " . $sourceName . " to " . $targetName);
        Log::comment("Data flow: " .
            $inputName . '.' . (empty($sourcePrefix) ? '' : $sourcePrefix) . '*' . " -> " .
            $porterName . '.PORT_*' . " -> " .
            $outputName . '.' . (empty($targetPrefix) ? '' : $targetPrefix) . '*');

        // Build artifacts.
        $inputStorage = Factory::storage($inputName, $sourcePrefix);
        $porterStorage = Factory::storage($porterName, 'PORT_');
        $outputStorage = Factory::storage($outputName, $targetPrefix);
        $postscriptStorage = Factory::storage($outputName, $targetPrefix); // Postscript names must match target names.
        $source = Factory::source($sourceName, $inputStorage, $porterStorage, $inputName);
        $target = Factory::target($targetName, $porterStorage, $outputStorage);
        $postscript = Factory::postscript($targetName, $outputStorage, $postscriptStorage);
        $fileTransfer = Factory::fileTransfer($source, $target, $porterName);

        // Main workflow.
        $start = microtime(true); // Start the timer.
        $this->setFlags($source, $target);
        if (!defined('PORTER_SKIP_EXPORT')) {
            $this->doExport($source, ($outputName === 'sql'));
        }
        $this->doImport($target);
        $this->doPostscript($postscript);
        $this->doFileTransfer($fileTransfer);

        // Report finished.
        Log::comment('! Porter never migrates user permissions. Remember to reset permissions.');
        Log::comment('! You may delete `PORT_` database tables if not needed for troubleshooting.');
        Log::comment("\n" . sprintf(
            '[ FINISHED at %s after running for %s ]',
            date('H:i:s e'),
            Log::formatElapsed(microtime(true) - $start)
        ) . "\n\n");
    }

    /**
     * Data pull from origin workflow.
     *
     * @throws \Exception
     */
    public function pull(Request $request): void
    {
        // Collect request.
        $originName = $request->getOrigin();
        $inputName = $request->getInput();

        // Build artifacts.
        $inputStorage = Factory::storage($inputName);
        $extractStorage = Factory::storage($inputName);
        $originStorage = Factory::storage($originName);
        $origin = Factory::origin($originName, $inputStorage, $extractStorage, $originStorage);

        // Report request.
        Log::comment("NITRO PORTER PULLING...");
        Log::comment("Pulling " . $originName . " into " . $inputName);
        Log::comment("\n" . sprintf(
            '[ STARTED at %s ]',
            date('H:i:s e')
        ) . "\n");

        // Main workflow.
        $start = microtime(true);
        $origin->run();

        // Report finished.
        Log::comment("\n" . sprintf(
            '[ FINISHED at %s after running for %s ]',
            date('H:i:s e'),
            Log::formatElapsed(microtime(true) - $start)
        ));
    }
}
