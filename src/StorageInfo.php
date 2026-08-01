<?php

namespace Porter;

/** DTO for info on workflow steps. */
final readonly class StorageInfo
{
    public function __construct(
        // Workflow state.
        public string $name = '',
        public int $memory = 0,
        public int $rows = 0,
        public array $content = [],
        // Times.
        public float $startTime = 0,
        public float $endTime = 0,
        public float $requestTime = 0,
        // Http details.
        public array $headers = [],
        public string $query = '',
        public int $http_code = 0,
        public string $endpoint = '',
    ) {
    }

    public function getElapsed(): float
    {
        $end = (!empty($this->endTime)) ? $this->endTime : microtime(true);
        return $end - $this->startTime;
    }

    public function getFirst(): mixed
    {
        return array_first($this->content);
    }

    public function getLast(): mixed
    {
        return array_last($this->content);
    }
}
