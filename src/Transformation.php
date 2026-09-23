<?php

namespace Porter;

/** Entity defining a single schema migration. */
class Transformation
{
    public function __construct(
        public string $outputSchemaName,
        public mixed $data = [], // Key/val, Builder, or table name for `select *`.
        public array $map = [],
        public array $filters = [],
    ) {
    }
}
