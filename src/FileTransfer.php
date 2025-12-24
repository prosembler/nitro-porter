<?php

namespace Porter;

use Staudenmeir\LaravelCte\Query\Builder;

class FileTransfer
{
    protected bool $supported = false;

    /**
     * @param Source $source
     * @param Target $target
     * @param Storage $porterStorage
     */
    public function __construct(protected Source $source, protected Target $target, protected Storage $porterStorage)
    {
        $this->supported = $this->evaluateSupport();
    }

    private function getBuilder(): Builder
    {
        return new Builder($this->porterStorage->getConnection());
    }

    /**
     * Determine whether we can initiate a file transfer.
     *
     * @return bool
     */
    protected function evaluateSupport(): bool
    {
        $support = true;

        // Valid source root.
        $sourceRoot = Config::getInstance()->get('source_root');
        if (empty($sourceRoot)) {
            Log::comment("Skipping file transfer: source_root not set in config.");
            $support = false;
        } elseif (!file_exists($sourceRoot)) {
            Log::comment("Skipping file transfer: source_root '{$sourceRoot}' does not exist.");
            $support = false;
        }

        // Valid target root.
        $targetRoot = Config::getInstance()->get('target_root');
        if (empty($targetRoot)) {
            Log::comment("Skipping file transfer: target_root not set in config.");
            $support = false;
        } elseif (!file_exists($targetRoot)) {
            Log::comment("Skipping file transfer: target_root '{$targetRoot}' does not exist.");
            $support = false;
        }

        // @todo

        return $support;
    }

    public function isSupported(): bool
    {
        return $this->supported;
    }

    /**
     * Run the file migration.
     */
    public function run(): void
    {
        $this->avatars();
        $this->avatarThumbnails();
        $this->attachments();
        $this->attachmentThumbnails();
    }

    /**
     * Replace the first character(s) of a filename.
     *
     * @param string $path
     * @param string $oldPrefix
     * @param string $newPrefix
     * @return string
     */
    protected function rePrefixFiles(string $path, string $oldPrefix, string $newPrefix): string
    {
        $i = pathinfo($path);
        return $i['dirname'] . '/' .  $newPrefix . substr($i['basename'], strlen($oldPrefix));
    }

    /**
     * @param string $inputFolder
     * @param string $outputFolder
     * @param callable|null $callback
     * @param array $params
     */
    protected function copyFiles(
        string $inputFolder,
        string $outputFolder,
        ?callable $callback = null,
        array $params = []
    ): void {
        //$this->verifyFolder($inputFolder);
        //$this->touchFolder($outputFolder);
        $resourceFolder = opendir($inputFolder);

        while (($file = readdir($resourceFolder)) !== false) {
            // Skip Unix files.
            if ($file == '.' || $file == '..') {
                continue;
            }

            // Recursively follow folders.
            $path = $inputFolder . '/' . $file;
            if (is_dir($path)) {
                $this->copyFiles($path, $outputFolder, $callback, $params);
                continue;
            }

            // Get Target path & name.
            $newPath = str_replace($inputFolder, $outputFolder, $path);
            $newPath = call_user_func_array($callback, [$newPath, $params]);
            copy($path, $newPath); // @todo Reporting.
        }
    }

    /**
     * Export avatars.
     */
    protected function avatars(): void
    {
        $map = $this->getBuilder()->from('User')
            ->select(['SourceAvatarFullPath', 'TargetAvatarFullPath'])
            ->whereNotNull('SourceAvatarFullPath')
            ->get();
        $found = 0;
        $missed = 0;
        foreach ($map as $row) {
            if (!empty($row->SourceAvatarFullPath) && !empty($row->TargetAvatarFullPath)) {
                touchFolder(dirname($row->TargetAvatarFullPath));
                copy($row->SourceAvatarFullPath, $row->TargetAvatarFullPath); // @todo TouchDir
                $found++;
            } else {
                $missed++;
            }
        }
        Log::comment("xfer: Copied {$found} avatars.");
        Log::comment("xfer: Missed {$missed} avatars.");
        // @todo time report
    }

    /**
     * Export avatar thumbnails.
     */
    protected function avatarThumbnails(): void
    {
        // @todo
    }

    /**
     * Export attachments.
     */
    protected function attachments(): void
    {
        $map = $this->getBuilder()->from('Media')
            ->select(['SourceFullPath', 'TargetFullPath'])
            ->whereNotNull('SourceFullPath')
            ->get();
        $found = 0;
        $missed = 0;
        foreach ($map as $row) {
            if (!empty($row->SourceFullPath) && !empty($row->TargetFullPath)) {
                touchFolder(dirname($row->TargetFullPath));
                copy($row->SourceFullPath, $row->TargetFullPath);
                $found++;
            } else {
                $missed++;
            }
        }
        Log::comment("xfer: Copied {$found} attachments.");
        Log::comment("xfer: Missed {$missed} attachments.");
        // @todo time report
    }

    /**
     * Export attachments.
     */
    protected function attachmentThumbnails(): void
    {
        // @todo
    }
}
