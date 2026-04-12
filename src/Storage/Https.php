<?php

namespace Porter\Storage;

use Porter\ConnectionManager;
use Porter\Log;
use Porter\Storage;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class Https extends Storage
{
    public const string USER_AGENT = 'NitroPorter (https://nitroporter.org, v' . APP_VERSION . ')';

    /** @var int Conservative limit on non-429 4xx/5xx errors to prevent bans. */
    public const int MAX_ERRORS = 5;

    /** @var int Number of tries to retry a request on non-4xx/5xx errors. */
    public const int MAX_RETRIES = 20;

    /** @var ConnectionManager */
    protected ConnectionManager $connectionManager;

    /** @var array Name => Value */
    protected array $headers = [];

    /** @var array Use 'code', 'message', 'headers', 'exception' */
    protected array $errors = [];

    /** @param ConnectionManager $c */
    public function __construct(ConnectionManager $c)
    {
        $this->connectionManager = $c;
        $this->setHeader('Content-Type', 'application/json');
        $this->setHeader('User-Agent', self::USER_AGENT);
    }

    /**
     * Add a header to be sent.
     * @param string $name Header name.
     * @param mixed $value Header value.
     */
    public function setHeader(string $name, mixed $value): void
    {
        $this->headers[$name] = $value;
    }

    /**
     * Headers to be sent.
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Log & store an HTTP error.
     * EXIT if MAX_ERRORS exceeded. otherwise pause.
     * @param array $errorInfo
     */
    public function addError(array $errorInfo): void
    {
        // Store the error.
        $this->errors[] = $errorInfo;

        // HTTP code & message log.
        $endpoint = (!empty($errorInfo['endpoint'])) ? $errorInfo['endpoint'] . ' ' : '';
        $msg = (!empty($errorInfo['message'])) ? $errorInfo['message'] . ' ' : '';
        $exception = (!empty($errorInfo['exception'])) ? $errorInfo['exception']->getMessage() : '';
        Log::comment("HTTP {$errorInfo['code']} " . $endpoint . $msg . "| " . $exception);

        // Header log.
        if (!empty($errorInfo['headers'])) {
            Log::comment('HEADERS: ' . json_encode($errorInfo['headers']));
        }

        // Cooldown.
        if (count($this->getErrors()) >= self::MAX_ERRORS) {
            $this->abort("MAX_ERRORS (" . self::MAX_ERRORS . ") reached");
        } else {
            sleep(5); // Pause a beat for safety.
        }
    }

    /**
     * 4xx/5xx error info.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Whether to attempt another get().
     * Based on HTTP 429 response and retry-after header.
     * @param int $code HTTP code
     * @param array $headers
     * @return bool|int Seconds to wait (or false to stop).
     */
    protected function retry(int $code, array $headers): bool|int
    {
        if (429 === $code && !empty($headers['retry-after'][0])) {
            $seconds = (int)$headers['retry-after'][0]; // Standard HTTP header.
            if ($seconds > 0 && $seconds < 300) { // Valid amount of time under 5 min.
                Log::comment("RATE LIMITED: Pausing for $seconds seconds");
                sleep($seconds); // TAKE A NAP BUT THEN FIRE ZE RETRY.
                return true;
            }
        }
        return false;
    }

    /**
     * Terminate Nitro Porter process.
     *
     * @param string $message
     */
    protected function abort(string $message): void
    {
        Log::comment("\nNITRO PORTER ABORTED at " . date('H:i:s e') . " — $message" . "\n");
        exit();
    }

    /**
     * Send the request & retrieve the response content.
     * @param string $endpoint URI to fetch.
     * @param array $query paramName => value.
     * @param int $retries How many times we've tried (recursively).
     * @return array Response content.
     */
    public function get(string $endpoint, array $query, int $retries = 0): array
    {
        // Build request.
        $endpoint = ltrim($endpoint, '/'); // No `/` at start or path of base_uri will be overwritten.
        $options = ['headers' => $this->getHeaders()];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        while (empty($parsed)) {
            // Detect excessive retries.
            if ($retries > self::MAX_RETRIES) {
                $this->abort("MAX_RETRIES (" . self::MAX_RETRIES . ") reached");
            }
            $retries++;

            // Send request.
            try {
                $response = $this->connectionManager->connection()->request('GET', $endpoint, $options);
            } catch (TransportExceptionInterface $e) { // Bad option passed.
                $this->abort("GET ($endpoint) " . $e->getMessage());
                exit(); // Stan is throwing a tantrum.
            }

            // Parse request.
            $parsed = $this->parseResponse($response);
        }

        return $parsed;
    }

    /**
     * Reference to the underlying library.
     *
     * @return HttpClientInterface
     */
    public function getHandle(): HttpClientInterface
    {
        return $this->connectionManager->connection();
    }

    /**
     * Redact headers for logs.
     *
     * @param array $options
     * @return array
     */
    protected function redactHeaders(array $options): array
    {
        if (isset($options['headers']['Authorization'])) {
            $options['headers']['Authorization'] = '_'; // no tokens in logs pls
        }
        if (isset($options['headers']['User-Agent'])) {
            $options['headers']['User-Agent'] = '_'; // abbreviate logs
        }
        return $options;
    }

    /**
     * @param string $resourceName
     * @param array $structure The final, combined structure to be written.
     */
    public function prepare(string $resourceName, array $structure): void
    {
        //
    }

    public function begin(): void
    {
        //
    }

    public function end(): void
    {
        //
    }

    /**
     * @param string $resourceName
     * @param array $structure
     * @return bool
     */
    public function exists(string $resourceName = '', array $structure = []): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function stream(array $row, array $structure, array $info = [], bool $final = false): array
    {
        return [];
    }

    /**
     * Parse `ResponseInterface` from get() for content & headers.
     * @return array Empty means retry.
     */
    protected function parseResponse(ResponseInterface $response): array
    {
        $code = 0;
        $headers = [];
        $message = '';
        try {
            $headers = $response->getHeaders(false); // Forcibly retrieve headers.
            $message = $response->getContent(false); // Forcibly retrieve body.
            $code = $response->getStatusCode();
            $content = $response->toArray();
        } catch (ClientExceptionInterface | ServerExceptionInterface $e) { // 4xx|5xx
            // Handle 429 (rate limit) errors & retries.
            if ($this->retry($code, $headers)) {
                return []; // RETRY.
            }
            // Collect & log (non-429) error before trying again.
            $this->addError(['code' => $code, 'message' => $message, 'headers' => $headers, 'exception' => $e]);
            return []; // RETRY.
        } catch (RedirectionExceptionInterface | TransportExceptionInterface | DecodingExceptionInterface $e) {
            // Redirect=3xx, Transport=network, Decoding=data. Unlikely to have consequences; log & retry.
            Log::comment("HTTP $code " . $e->getMessage());
            return []; // RETRY.
        }

        return [$content, $headers, $code];
    }

    /**
     * Download a file & log issues.
     * @return bool Whether the download was successful.
     */
    public function download(string $url, string $path): bool
    {
        // Get & validate response.
        try {
            $response = $this->connectionManager->connection()->request('GET', $url);
            $response->getStatusCode();
        } catch (ClientExceptionInterface $e) {
            Log::comment("4xx error downloading [$url]", false);
            return false;
        } catch (ExceptionInterface $e) {
            $type = str_replace('ExceptionInterface', '', get_class($e));
            Log::comment("$type error downloading [$url] " . $e->getMessage());
            return false;
        }

        // Write file.
        $fileHandler = fopen($path, 'w');
        try {
            foreach ($this->connectionManager->connection()->stream($response) as $chunk) {
                fwrite($fileHandler, $chunk->getContent());
            }
        } catch (ExceptionInterface $e) {
            Log::comment("Failed to write $url to $path");
            return false;
        }

        return true;
    }

    /**
     * Download files in async batches.
     * Gotta go fast? It'll cost you feedback on whether it worked...
     * @see https://symfony.com/doc/current/http_client.html#multiplexing-responses
     * @see \Symfony\Component\HttpClient\Retry\GenericRetryStrategy to modify retry defaults.
     * @see https://www.php.net/manual/en/resource.php for what counts as a 'stream' resource in PHP.
     * @param array $downloads URL => SAVE_PATH
     */
    public function asyncDownload(array $downloads): void
    {
        // Setup.
        $start = microtime(true);
        $countRequests = count($downloads);
        $countResponses = 0;
        $memoryPeak = memory_get_usage();
        $client = new RetryableHttpClient($this->connectionManager->connection()); // maxRetries: 3
        array_map('fclose', get_resources('stream')); // Close ANY open streams to prevent memory creep.

        // Send requests.
        $responses = [];
        foreach ($downloads as $url => $path) {
            if (empty($path) || file_exists($path)) {
                unset($downloads[$url]); // Don't download duplicates.
                continue;
            }
            try {
                $responses[$url] = $client->request('GET', $url, ['timeout' => 3]);
            } catch (ExceptionInterface $e) {
                unset($responses[$url]);
                Log::comment("> failed request: {$url} — " . $e->getMessage());
            }
            $memoryPeak = max(memory_get_usage(), $memoryPeak);
        }

        // Process responses async.
        $fileHandles = [];
        foreach ($client->stream($responses) as $response => $chunk) {
            $url = array_search($response, $responses, true); // Returned key is the URL.
            try {
                if ($chunk->isFirst()) {
                    $fileHandles[$url] = fopen($downloads[$url], 'wb');
                    if (false === $fileHandles[$url]) {
                        Log::comment("> failed to open stream: $url —> " . $downloads[$url]);
                    }
                } elseif ($chunk->isLast() && $fileHandles[$url]) {
                    fclose($fileHandles[$url]);
                    unset($fileHandles[$url]); // We may still be growing this array, so prune it as possible.
                    $countResponses++;
                } elseif ($fileHandles[$url]) {
                    fwrite($fileHandles[$url], $chunk->getContent());
                } // else: Already logged stream did not open.
                $memoryPeak = max(memory_get_usage(), $memoryPeak);
            } catch (ExceptionInterface $e) {
                Log::comment("> failed download: {$url} [msg: " . $e->getMessage() . ']');
                if (isset($fileHandles[$url])) {
                    fclose($fileHandles[$url]); // Attempt to ternimate stream to prevent runaway memory usage.
                    unset($fileHandles[$url]); // Don't come back to this one.
                }
                unlink($downloads[$url]); // Attempt file cleanup so we can retry.
            }
        }

        // Report.
        Log::download(
            memory: max(memory_get_usage(), $memoryPeak),
            elapsed: microtime(true) - $start,
            countRequest: $countRequests,
            countResponse: $countResponses,
        );
    }
}
