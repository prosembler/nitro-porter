<?php

/**
 * Just a test source package.
 */

namespace Porter\Source;

use Porter\Source;

/**
 * @see Package::MANIFEST for a complete list of method names you can use.
 */
class Test extends Source
{
    public const array INFO = [
        'name' => 'Test', // Package name users will see.
        'defaultTablePrefix' => '', // Default table prefix this software uses, if you know it.
        'charsetTable' => 'comments',  // Usually put the comments table name here. Used to derive charset.
    ];

    protected function users(): void
    {
        //
    }

    protected function roles(): void
    {
        //
    }

    protected function categories(): void
    {
        //
    }

    protected function discussions(): void
    {
        //
    }

    protected function comments(): void
    {
        //
    }
}
