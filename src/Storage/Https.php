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
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Https extends Storage
{
    public const string USER_AGENT = 'NitroPorter (https://nitroporter.org, v' . APP_VERSION . ')';

    /** @var ConnectionManager */
    protected ConnectionManager $connectionManager;

    /** @var array */
    protected array $headers = [];

    /** @param ConnectionManager $c */
    public function __construct(ConnectionManager $c)
    {
        $this->connectionManager = $c;
    }

    /**
     * @param string $name Name of the data chunk / table to be written.
     * @param array $map
     * @param array $structure
     * @param ResultSet|Builder $data
     * @param array $filters
     * @return array Information about the results.
     */
    public function store(string $name, array $map, array $structure, $data, array $filters): array
    {
        return [];
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function setHeader(string $name, mixed $value): void
    {
        $this->headers[$name] = $value;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return array_merge([
            'Content-Type' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ], $this->headers);
    }

    /**
     * Send the request & retrieve the response content.
     * @param string $endpoint
     * @param array $query
     * @return array Response content.
     */
    public function get(string $endpoint, array $query): array
    {
        // Send request.
        $options = [
            'headers' => $this->getHeaders(),
            'query' => $query,
        ];
        try {
            $response = $this->connectionManager->connection()->request('GET', $endpoint, $options);
        } catch (TransportExceptionInterface $e) {
            Log::comment("ERROR: GET ($endpoint) " . $e->getMessage());
            return []; // Failure is on our side; safe to continue.
        }
        if (Config::getInstance()->debugEnabled()) {
            // Enable debugging mode to see full request in logs.
            $options['headers']['Authorization'] = '{redacted}'; // no tokens in logs pls
            Log::comment("\nSENT: GET ($endpoint) " . json_encode($options) . "\n");
        }

        // Parse response.
        $code = 0;
        $message = '(Empty body)';
        $content = [];
        try {
            $code = $response->getStatusCode();
            //$headers = $response->getHeaders();
            $message = $response->getContent();
            $content = $response->toArray();
        } catch (ClientExceptionInterface | ServerExceptionInterface $e) { // 4xx|5xx
            Log::comment("HTTP $code ($endpoint) " . $message .  " | " . $e->getMessage());
            Log::comment("NITRO PORTER ABORTED BY $code HTTP CODE RESPONSE" . "\n");
            exit(); // Safety measure; don't get banned.
        } catch (RedirectionExceptionInterface | TransportExceptionInterface | DecodingExceptionInterface $e) {
            // Redirection = 3xx, Transport = network, Decoding = array-specific
            Log::comment("HTTP $code ($endpoint) " . $e->getMessage());
        }

        if (Config::getInstance()->debugEnabled()) {
            // Enable debugging mode to see full response in logs.
            Log::comment("REPLY: HTTP $code $message\n");
        }

        return $content;
    }

    /**
     * @param string $name
     * @param array $structure The final, combined structure to be written.
     */
    public function prepare(string $name, array $structure): void
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
     * @param array $row
     * @param array $structure
     * @param bool $final
     */
    public function stream(array $row, array $structure, bool $final = false): void
    {
        //
    }

    /**
     * @return HttpClientInterface
     */
    public function getHandle(): HttpClientInterface
    {
        return $this->connectionManager->connection();
    }
}
