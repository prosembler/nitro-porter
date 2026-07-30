<?php

namespace Porter\Https;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class DiscordRetryStrategy extends GenericRetryStrategy
{
    // Tracks when specific buckets or the global application reset
    private array $locks = [];
    private float $lockUntil = 0.0;

    /**
     * Discord has both a 50/sec rate limit (API) and a 10K/10min rate limit (Cloudflare).
     * 1 second = 1,000 milliseconds = 1,000,000 microseconds.
     * Limits are therefore 1/20000 microseconds (API) and 1/62500 microseconds (CF).
     * Because scrapes are likely to exceed 10 minutes, use the slower rate / longer wait.
     */
    public const int MIN_MICROSECONDS = 62500; // Wait to force rate limit compliance.

    public function __construct(
        array $httpCodes = [400, 401, 403, 404, 408, 409, 410, 423, 425, 429, 500, 502, 503, 504, 507, 510],
        int $delayMs = self::MIN_MICROSECONDS,
        float $multiplier = 2.0,
        int $maxDelayMs = 60000
    ) {
        parent::__construct($httpCodes, $delayMs, $multiplier, $maxDelayMs);
    }

    /**
     * Decide if we should retry + get rate limit metrics for upcoming requests.
     */
    public function shouldRetry(
        AsyncContext $context,
        ?string $responseContent,
        ?TransportExceptionInterface $exception
    ): ?bool {
        $headers = $context->getHeaders();
        $statusCode = $context->getStatusCode();
        $bucket = $headers['x-ratelimit-bucket'][0] ?? null;
        $scope = $headers['x-ratelimit-scope'][0] ?? null;
        $resetAfter = (float) ($headers['x-ratelimit-reset-after'][0] ?? 0.0);
        $remaining = (int) ($headers['x-ratelimit-remaining'][0] ?? 1);
        $until = microtime(true) + $resetAfter;

        if ($statusCode === 429 && $scope === 'global') {
            $this->lockUntil = $until;
        } elseif ($bucket && ($remaining === 0 || $statusCode === 429)) {
            $this->locks[$bucket] = $until;
        }

        return parent::shouldRetry($context, $responseContent, $exception);
    }

    /**
     * Force a wait before sending a request per global or bucket locks.
     */
    public function getDelay(
        AsyncContext $context,
        ?string $responseContent,
        ?TransportExceptionInterface $exception
    ): int {
        $headers = $context->getHeaders();
        $bucket = $headers['x-ratelimit-bucket'][0] ?? null;
        $now = microtime(true);
        $delay = 0.0;

        // Check lockout status.
        if ($this->lockUntil > $now) {
            $delay = max($delay, $this->lockUntil - $now);
        }
        if ($bucket && !empty($this->locks[$bucket]) && $this->locks[$bucket] > $now) {
            $delay = max($delay, $this->locks[$bucket] - $now);
        }

        // Enforce lockout.
        if ($delay > 0) {
            return (int) ($delay * 1000);
        }

        return parent::getDelay($context, $responseContent, $exception);
    }
}
