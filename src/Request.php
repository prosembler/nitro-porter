<?php

namespace Porter;

final readonly class Request
{
    private ?string $originName;
    private ?string $sourceName;
    private ?string $targetName;
    private ?string $inputStorage;
    private ?string $outputStorage;
    private ?string $porterStorage;
    private ?string $inputTablePrefix;
    private ?string $outputTablePrefix;

    /**
     * Build a valid Porter request.
     *
     * @param ?string $originPackage Origin package alias
     * @param ?string $sourcePackage Source package alias (or 'port')
     * @param ?string $targetPackage Target package alias (or 'file', 'sql')
     * @param ?string $inputStorage Storage alias in config.php
     * @param ?string $outputStorage Storage alias in config.php
     * @param ?string $porterStorage Storage alias in config.php
     * @param ?string $inputTablePrefix If the input is a database, override source package with this table prefix.
     * @param ?string $outputTablePrefix If the output is a database, override target package with this table prefix.
     * @param ?string $components CSV of components (ex: `users,categories,discussions`)
     * @param ?bool $unbatch Whether to limit batches to a size of 1.
     * @param ?bool $dumpsql Whether to echo the SQL of $data to be transferred.
     * @param ?bool $skipexport Whether to skip the export step (Source).
     * @throws \Exception
     */
    public function __construct(
        ?string $originPackage = null,
        ?string $sourcePackage = null,
        ?string $targetPackage = null,
        ?string $inputStorage = null,
        ?string $outputStorage = null,
        ?string $porterStorage = null,
        ?string $inputTablePrefix = null,
        ?string $outputTablePrefix = null,
        ?string $components = null,
        ?bool $unbatch = false,
        ?bool $dumpsql = false,
        ?bool $skipexport = false,
    ) {
        $this->originName = $originPackage ?? Config::getInstance()->get('origin');
        $this->sourceName = $sourcePackage ?? Config::getInstance()->get('source');
        $this->targetName = $targetPackage ?? Config::getInstance()->get('target');

        $this->inputStorage = $inputStorage ?? Config::getInstance()->get('input_alias');
        $this->outputStorage = $outputStorage ?? Config::getInstance()->get('output_alias');
        // `PORT_` intermediary MUST be relational; fallback to the output storage.
        $this->porterStorage = $porterStorage ?? Config::getInstance()->get('porter_alias') ?: $this->outputStorage;

        // Table prefixes: CLI > Config > Package defaults
        $i = $inputTablePrefix ?? Config::getInstance()->get('source_prefix');
        if (!empty($this->sourceName) && empty($i)) {
            $i = Factory::source($this->sourceName)->getPrefix();
        }
        $this->inputTablePrefix = $i;
        $o = $outputTablePrefix ?? Config::getInstance()->get('target_prefix');
        if (!empty($this->targetName) && empty($o)) {
            $o = Factory::target($this->targetName)->getPrefix();
        }
        $this->outputTablePrefix = $o;

        // Debug settings.
        if (!empty($components)) { /** @see /manifest.php $components */
            $components = explode(',', $components);
            if (count(array_diff($components, Support::list()))) {
                throw new \Exception('Invalid components requested that are not in manifest.');
            }
            define('PORTER_COMPONENTS', $components);
        }
        if ($unbatch) { /** @see \Porter\Storage\Database */
            define('PORTER_STORAGE_UNBATCH', true);
        }
        if ($dumpsql) { /** @see \Porter\Storage\Database::store(), \Porter\Source::export() */
            define('PORTER_STORAGE_DUMPSQL', true);
        }
        if ($skipexport) {
            define('PORTER_SKIP_EXPORT', true);
        }
    }

    public function getOrigin(): ?string
    {
        return $this->originName;
    }

    public function getSource(): ?string
    {
        return $this->sourceName;
    }

    public function getTarget(): ?string
    {
        return $this->targetName;
    }

    public function getInput(): ?string
    {
        return $this->inputStorage;
    }

    public function getOutput(): ?string
    {
        return $this->outputStorage;
    }

    public function getPorter(): ?string
    {
        return $this->porterStorage;
    }

    public function getInputTablePrefix(): ?string
    {
        return $this->inputTablePrefix;
    }

    public function getOutputTablePrefix(): ?string
    {
        return $this->outputTablePrefix;
    }
}
