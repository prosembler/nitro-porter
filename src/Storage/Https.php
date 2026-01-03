<?php

namespace Porter\Storage;

use Illuminate\Database\Query\Builder;
use Porter\Config;
use Porter\ConnectionManager;
use Porter\Database\ResultSet;
use Porter\Log;
use Porter\Storage;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
     * Does nothing... yet.
     * @inheritdoc
     */
    public function store(string $name, array $map, array $structure, $data, array $filters): array
    {
        return [];
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
        $this->errors[] = $errorInfo;
        Log::comment("HTTP {$errorInfo['code']} ({$errorInfo['endpoint']}) " .
            $errorInfo['message'] . " | " . $errorInfo['exception']->getMessage());
        Log::comment('HEADERS: ' . json_encode($errorInfo['headers']));
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
     * @param string $endpoint URI, no `/` at start or path of base_uri will be overwritten.
     * @param array $query paramName => value
     * @param int $retries How many times we've tried (recursively).
     * @return array Response content.
     */
    public function get(string $endpoint, array $query, int $retries = 0): array
    {
        // Detect excessive retries.
        if ($retries > self::MAX_RETRIES) {
            $this->abort("MAX_RETRIES (" . self::MAX_RETRIES . ") reached");
        }

        // Send request.
        $options = [
            'headers' => $this->getHeaders(),
            'query' => $query,
        ];
        if (Config::getInstance()->debugEnabled()) { // Show full request in logs.
            Log::comment("\nSENT: GET ($endpoint)\n> " . json_encode($this->redactHeaders($options)));
        }
        try {
            $response = $this->connectionManager->connection()->request('GET', $endpoint, $options);
        } catch (TransportExceptionInterface $e) { // Bad option passed.
            $this->abort("GET ($endpoint) " . $e->getMessage());
            exit(); // Stan is throwing a tantrum.
        }

        // Parse response.
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
                return $this->get($endpoint, $query, $retries++); // TRY AGAIN.
            }
            // Collect & log (non-429) error before trying again.
            $this->addError(['code' => $code, 'message' => $message, 'headers' => $headers, 'exception' => $e]);
            return $this->get($endpoint, $query, $retries++); // TRY AGAIN.
        } catch (RedirectionExceptionInterface | TransportExceptionInterface | DecodingExceptionInterface $e) {
            // Redirect=3xx, Transport=network, Decoding=data. Unlikely to have consequences; log & retry.
            Log::comment("HTTP $code ($endpoint) " . $e->getMessage());
            return $this->get($endpoint, $query, $retries++); // TRY AGAIN.
        }
        if (Config::getInstance()->debugEnabled()) { // Show (good) full response in logs.
            Log::comment("REPLY: HTTP $code (" . count($content) . " records)");
        }

        return [$content, $headers];
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
    public function stream(array $row, array $structure, bool $final = false): void
    {
        //
    }
}
