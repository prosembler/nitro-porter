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

    /** @var int Conservative limit on non-429 4xx/5xx errors PER MINUTE to prevent bans. */
    public const int MAX_ERRORS = 7;

    /** @var int Number of tries to retry a request on non-4xx/5xx errors. */
    public const int MAX_RETRIES = 3;

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

    /** Allow an Origin to reset the connection. */
    public function resetConnection(string $originName): void
    {
        $this->connectionManager = new ConnectionManager($originName);
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
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Log & store an HTTP error.
     * EXIT if MAX_ERRORS exceeded. otherwise pause.
     */
    public function addError(array $errorInfo): void
    {
        // Store the error.
        $stamp = date('dHi');
        $this->errors[$stamp] = $errorInfo;

        // HTTP code & message log.
        $endpoint = (!empty($errorInfo['endpoint'])) ? $errorInfo['endpoint'] . ' ' : '';
        $msg = (!empty($errorInfo['message'])) ? $errorInfo['message'] . ' ' : '';
        $exception = (!empty($errorInfo['exception'])) ? $errorInfo['exception']->getMessage() : '';
        Log::comment("HTTP {$errorInfo['code']} " . $endpoint . $msg . "| " . $exception);

        // Header log.
        if (!empty($errorInfo['headers'])) {
            Log::comment('HEADERS: ' . json_encode($errorInfo['headers']));
        }
    }

    /**
     * 4xx/5xx error info.
     *
     * Stamp creates per-minute buckets using index date('dHi') or DDHHMM, e.g.'012345' (first day of month, 11:45pm)
     * Defaults to current minute. Stamp=0 returns all errors.
     *
     * @see self::addError()
     * @return array of error info ['endpoint', 'headers', 'message', 'exception', 'code']
     */
    public function getErrors(?int $stamp = null): array
    {
        if (null === $stamp) {
            $stamp = date('dHi'); // Now.
        }

        return (!empty($stamp) && empty($this->errors[$stamp])) ? $this->errors[$stamp] : $this->errors;
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
                return [];
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

            // Detect excessive recent errors.
            if (count($this->getErrors()) >= self::MAX_ERRORS) {
                $this->abort("MAX_ERRORS (" . self::MAX_ERRORS . ") reached");
            }
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
            sleep(5); // Pause a beat for safety.
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
     *
     * Gotta go fast? It'll cost you feedback on whether it worked...
     * This is one of the most memory-intense pieces of Porter.
     * Be extraordinarily careful with memory management when modifying.
     *
     * Apr 2026: Suspect memory leak in HttpClient.
     *   - On HTTP error, memory footprint still grows despite response::cancel(), fclose(), gc_collect_cycles().
     *   - Hack/sidestep would be destroy/recreate HttpClient per batch, but it'd add significant complexity.
     *
     * @see https://symfony.com/doc/current/http_client.html#multiplexing-responses
     * @see \Symfony\Component\HttpClient\Retry\GenericRetryStrategy to modify retry defaults.
     * @see https://www.php.net/manual/en/resource.php for what counts as a 'stream' resource in PHP.
     * @param array $downloads URL => SAVE_PATH
     * @return int Number of errors encountered.
     */
    public function asyncDownload(array $downloads): int
    {
        // Verify work to do.
        $countRequests = count($downloads);
        if (!$countRequests) {
            return 0;
        }

        // Setup.
        $start = microtime(true);
        $memoryPeak = memory_get_usage();
        $errors = 0;
        $client = new RetryableHttpClient($this->connectionManager->connection()); // maxRetries: 3

        // Send requests.
        $responses = [];
        foreach ($downloads as $url => $path) {
            try {
                $responses[$url] = $client->request('GET', $url, ['timeout' => 3, 'buffer' => false]);
            } catch (ExceptionInterface $e) {
                $errors++;
                unset($responses[$url]);
                Log::comment("> failed request: $url — " . $e->getMessage());
            }
            $memoryPeak = max(memory_get_usage(), $memoryPeak);
        }

        // Process responses async.
        $files = []; // File handles for fopen() etc.
        $countResponses = 0;
        foreach ($client->stream($responses) as $response => $chunk) {
            $url = array_search($response, $responses, true); // Returned key is the URL.
            try {
                if ($chunk->isFirst()) {
                    // Start a file.
                    $files[$url] = fopen($downloads[$url], 'wb');
                    if (false === $files[$url]) {
                        $errors++;
                        Log::comment("> failed to open stream: $url —> " . $downloads[$url]);
                    }
                } elseif ($chunk->isLast() && $files[$url]) {
                    // Finish a file.
                    fclose($files[$url]);
                    unset($files[$url]); // We may still be growing this array, so prune it as possible.
                    $countResponses++;
                } elseif ($files[$url]) {
                    // Continue a file.
                    fwrite($files[$url], $chunk->getContent());
                } // else: Already logged stream did not open.
                $memoryPeak = max(memory_get_usage(), $memoryPeak);
            } catch (ExceptionInterface $e) {
                $errors++;
                Log::comment("> failed download: $url [msg: " . $e->getMessage() . ']');

                // Aggressively purge the file from memory.
                $response->cancel(); // Terminate the response to clear its cached data.
                unset($responses[$url]);
                if (isset($files[$url])) {
                    fflush($files[$url]); // Dump stream buffer.
                    fclose($files[$url]); // Attempt to terminate stream.
                    unset($files[$url]); // Don't come back to this one.
                }
                if (file_exists($downloads[$url])) {
                    unlink($downloads[$url]); // Attempt file cleanup so we can retry.
                }
                gc_collect_cycles(); // Force garbage collection to preserve memory.
            }
        }
        flush(); // Clear system write buffers.

        // Report.
        Log::download(
            memory: max(memory_get_usage(), $memoryPeak),
            elapsed: microtime(true) - $start,
            countRequest: $countRequests,
            countResponse: $countResponses,
        );

        return $errors;
    }
}
