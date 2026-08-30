<?php

namespace Porter;

use Staudenmeir\LaravelCte\Query\Builder;

abstract class Origin extends Package
{
    /** @var array */
    protected array $config = [];

    /** @var Storage\Https Where the origin data is from (read-only HTTPS). */
    protected Storage\Https $originStorage;

    /** @var string Folder path to download attachment files into. */
    protected string $attachmentFolder;

    /**
     * @throws \Exception
     */
    public function __construct(
        protected ?Storage\Database $outputStorage = null, // Where data is being written.
        protected ?Storage\Database $extractStorage = null, // Second connection for simultaneous read/write.
        public string $packageName = '',
    ) {
        $this->schema = Schema::load(strtolower($packageName));
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function addHttps(Storage\Https $originStorage): void
    {
        $this->originStorage = $originStorage;
    }

    /**
     * Provide a query builder for the output database.
     * @internal For `Origin` packages.
     * @return Builder
     */
    protected function outputQB(): Builder
    {
        return new Builder($this->outputStorage->getHandle());
    }

    /**
     * @param string $endpoint
     * @param array $fields
     * @param string $tableName
     * @param string|null $key A non-null value will discard other data & use this key (only) as the data.
     * @param array $query
     * @param array $map
     * @param array $storeAll A set of [name => value] to insert into ALL resulting records.
     * @return StorageInfo $info from get() and store()
     * @see Migration::import() for comparison.
     */
    protected function pull(
        string $endpoint,
        array $fields,
        string $tableName,
        ?string $key = null,
        array $query = [],
        array $map = [],
        array $storeAll = []
    ): StorageInfo {
        // Start timer.
        $start = microtime(true);

        // Prepare the storage medium for the incoming structure.
        $this->outputStorage->protectTable($tableName); // Do not reset data from origins every run.
        $this->outputStorage->ignoreTable($tableName);  // Allow duplicate inserts.
        $this->outputStorage->prepare($tableName, $fields);

        // Retrieve data from the origin.
        $originInfo = $this->originStorage->get($endpoint, $query);
        $content = $originInfo->content;

        // Discard the rest of the content if we only want a key's contents.
        if (!empty($key) && !empty($originInfo->content)) {
            if (isset($originInfo->content[$key])) {
                $content = $originInfo->content[$key];
            } else {
                Log::comment("> key '{$key}' not found in response from '{$endpoint}'.");
            }
        }

        // Add $storeAll to the results.
        foreach ($content as &$item) {
            $item = array_merge($item, $storeAll);
        }

        // Store the $content.
        $info = $this->outputStorage->store($tableName, $map, $fields, $content, []);

        // Send info.
        $info = new StorageInfo(
            name: $tableName,
            memory: $info->memory,
            rows: $info->rows,
            content: $content,
            startTime: $start,
            endTime: microtime(true),
            requestTime: $originInfo->requestTime,
            headers: $originInfo->headers,
            query: $originInfo->query,
            http_code: $originInfo->http_code,
            endpoint: $endpoint,
        );
        Log::pull($info);

        return $info;
    }

    /**
     * Extract a list to its own table.
     */
    protected function extract(string $tableName, array $fields, array $data): StorageInfo
    {
        // May be called blindly without checking for records.
        if (empty($data)) {
            return new StorageInfo(
                rows: 0
            );
        }

        // Start timer.
        $start = microtime(true);

        // Prepare the storage medium for the incoming structure.
        $this->extractStorage->protectTable($tableName); // Do not reset data from origins every run.
        $this->extractStorage->ignoreTable($tableName);  // Allow duplicate inserts.
        $this->extractStorage->prepare($tableName, $fields);

        // Store the data.
        $info = $this->extractStorage->store($tableName, [], $fields, $data, []);

        // Report.
        $info = new StorageInfo(
            name: $info->name,
            memory: $info->memory,
            rows: $info->rows,
            startTime: $start,
        );
        Log::storage('> extract', $info);

        return $info;
    }

    /**
     * Folder to download files into.
     *
     * @return string source_root/$name/
     */
    protected function getDownloadFolder(string $name): string
    {
        static $folders = [];
        if (!empty($folders[$name])) {
            return $folders[$name];
        }

        // Get the source_root.
        $srcRoot = Config::getInstance()->get('source_root');
        if (empty($srcRoot)) {
            Log::comment("No download folder defined in config (`source_root`)");
            return '';
        }

        // Build the path & return it.
        $folder = rtrim($srcRoot, '/') . '/' . trim($name, '/');
        $exists = FileTransfer::touchFolder($folder);
        $folders[$name] = ($exists) ? $folder . '/' : '';
        return $folders[$name];
    }

    /**
     * Retrieve the file.
     */
    protected function getFile(string $url, string $filename): void
    {
        if (!$this->attachmentFolder) {
            return;
        }
        $path = $this->attachmentFolder . $filename;
        if (!file_exists($path)) {
            $this->originStorage->download($url, $path);
        } else {
            Log::comment("Notice: Attachment '{$filename}' already exists.");
        }
    }

    /**
     * Change a filename so that the basename is no more than $length characters.
     *
     * Prevents error "Failed to open stream: File name too long".
     */
    protected function limitFilenameLength(string $filename, int $length = 100): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return substr(pathinfo($filename, PATHINFO_FILENAME), 0, $length) . '.' . $ext;
    }
}
