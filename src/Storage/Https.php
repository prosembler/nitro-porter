<?php

namespace Porter\Storage;

use Illuminate\Database\Query\Builder;
use Porter\ConnectionManager;
use Porter\Database\ResultSet;
use Porter\Log;
use Porter\Storage;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Https extends Storage
{
    public const string USER_AGENT = 'NitroPorter (https://nitroporter.org, v4)';

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
     * @return array Response content.
     * @throws ExceptionInterface
     */
    public function get(string $endpoint, array $request): array
    {
        // Send the request.
        $options = [
            'headers' => $this->getHeaders(),
            'body' => json_encode($request),
        ];
        $response = $this->connectionManager->connection()->request('GET', $endpoint, $options);
        $content = [];

        // Debug request.
        if (\Porter\Config::getInstance()->debugEnabled()) {
            Log::comment("GET ($endpoint): " . json_encode($options));
        }

        // Get content, check status, & log.
        $code = $response->getStatusCode();
        if ($code === 200) {
            try {
                $content = $response->toArray();
            } catch (ExceptionInterface $e) { // Empty $content possible.
                Log::comment("HTTP 200 ($endpoint), but client error: " . $e->getMessage());
            }
        } else { // Panic!
            try {
                $message = $response->getContent();
                Log::comment("HTTP $code ($endpoint): " . $message);
            } catch (ExceptionInterface $e) {
                Log::comment("HTTP $code ($endpoint): " . $e->getMessage());
            }
            Log::comment('NITRO PORTER ABORTED BY BAD PULL: NON-200 HTTP CODE RESPONSE');
            exit(); // Safety measure so we don't spam HTTP errors and get banned.
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
