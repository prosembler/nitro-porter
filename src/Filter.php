<?php

namespace Porter;

class Filter
{
    public function __construct(protected mixed $value, protected string $columnName = '', protected array $row = [])
    {
        // @todo final properties
    }

    public function __invoke(): mixed
    {
        throw new \LogicException('Filter not implemented');
    }
}
