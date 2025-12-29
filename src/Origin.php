<?php

namespace Porter;

abstract class Origin extends Package
{
    public const SUPPORTED = [
        'name' => '',
    ];

    /** @var Storage\Database Where the data is being written. */
    protected Storage\Database $output;

    /** @var Storage\Https Where the source data is from (read-only). */
    protected Storage\Https $input;

    /** @var array */
    protected array $config = [];

    /** @var array */
    protected array $headers = [];

    /**
     * @param Storage\Https $input
     * @param Storage\Database $output
     * @throws \Exception
     */
    public function __construct(Storage\Https $input, Storage\Database $output)
    {
        $this->output = $output;
        $this->input = $input;
        $this->config = Config::getInstance()->getConnectionAlias(strtolower(__CLASS__));
    }

    abstract public function run(): void;

    /**
     * Get name of the origin package.
     *
     * @return string
     */
    public static function getName(): string
    {
        return static::SUPPORTED['name'];
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function setHeader(string $name, mixed $value): void
    {
        $this->headers[$name] = $value;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param string $endpoint
     * @param array $request
     * @param array $fields
     * @param string $tableName
     * @return array
     */
    protected function pull(string $endpoint, array $request, array $fields, string $tableName): array
    {
        $response = $this->input->get($endpoint, $request, $this->getHeaders());
        //$this->output->store($tableName, [], $fields, $response, []); //@todo update signature
        return []; // @todo need at least IDs
    }
}
