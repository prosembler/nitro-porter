<?php

namespace Porter;

abstract class Origin extends Package
{
    /** @var Storage\Database Where the data is being written. */
    protected Storage\Database $output;

    /** @var Storage\Https Where the source data is from (read-only). */
    protected Storage\Https $input;

    /** @var array */
    protected array $config = [];

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

    /**
     * @param string $endpoint
     * @param array $request
     * @param array $fields
     * @param string $tableName
     * @return array
     */
    protected function pull(string $endpoint, array $request, array $fields, string $tableName): array
    {
        $response = $this->input->get($endpoint, $request);
        //$this->output->store($tableName, [], $fields, $response, []); //@todo update signature
        return []; // @todo need at least IDs
    }
}
