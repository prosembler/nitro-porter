<?php

namespace Porter;

use Staudenmeir\LaravelCte\Query\Builder;

abstract class Source extends Package
{
    /**
     * @var array Required tables, columns set per exporter
     */
    public array $sourceTables = [];

    public function __construct(protected ?Storage $inputStorage = null, protected ?Storage $porterStorage = null)
    {
    }

    public function inputQB()
    {
        return new Builder($this->inputStorage->getHandle());
    }

    public function porterQB()
    {
        return new Builder($this->porterStorage->getHandle());
    }

    /**
     * @return string
     */
    public static function getCharsetTable(): string
    {
        $charset = '';
        if (isset(static::SUPPORTED['charsetTable'])) {
            $charset = static::SUPPORTED['charsetTable'];
        }
        return $charset;
    }

    /**
     * Return the requested path (without a trailing slash).
     *
     * @param string $type
     * @param bool $addFullPath
     * @return string
     */
    public function getPath(string $type, bool $addFullPath = false): string
    {
        $folder = rtrim(static::SUPPORTED[$type . 'Path'] ?? '', '/');
        if ($addFullPath && Config::getInstance()->get('source_root')) {
            $folder = rtrim(Config::getInstance()->get('source_root'), '/') . '/' . trim($folder, '/');
        }
        return $folder;
    }
}
