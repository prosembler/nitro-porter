<?php

namespace Porter;

/** @see manifest.php for a list of all possible components. */
class Component
{
    //public string $name;
    //public array $prerequisites = [];

    /**
     * @param array<Transformation> $transformations
     */
    public function __construct(public array $transformations)
    {
    }
}
