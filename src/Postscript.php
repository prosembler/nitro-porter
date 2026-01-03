<?php

namespace Porter;

/**
 * Custom data finalization post-migration for database targets.
 *
 * Extend this class per-Target as required. It will automatically run after the Target of the same name.
 * If using file-based or remote storage, handle this outside Porter instead.
 */
abstract class Postscript extends Package
{
    /** Main process, custom per package. */
    abstract public function run(?Migration $port = null): void;
}
