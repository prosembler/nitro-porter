<?php

namespace Porter\Storage;

use MongoDB\Collection;
use MongoDB\Database;
use Porter\ConnectionManager;
use Porter\Storage;

/**
 * Document storage for targets that run on MongoDB.
 *
 * Buffers inserts & flushes them with `insertMany()`, and exposes the read/update primitives a
 * document target needs. Collections & document shapes are the target's business, not this class'.
 * No schema to prepare, so the relational `Storage` contract methods are no-ops.
 */
class Mongo extends Storage
{
    /** @var int How many documents to write per `insertMany()`. */
    public const int INSERT_BATCH = 1000;

    /** @var ConnectionManager */
    protected ConnectionManager $connectionManager;

    /** @var array Documents pending insert, keyed by collection name. */
    protected array $buffer = [];

    /** @param ConnectionManager $c */
    public function __construct(ConnectionManager $c)
    {
        $this->connectionManager = $c;
    }

    /**
     * Reference to the underlying library.
     *
     * Note this is a Mongo `Database`, not an Illuminate `Connection`, so the `dbOutput()` and
     * `dbPorter()` accessors on Target/Postscript cannot be pointed at this storage. Packages
     * using it must reach for the primitives below instead.
     *
     * @return Database
     */
    public function getHandle(): Database
    {
        return $this->connectionManager->connection();
    }

    /**
     * Buffer a document for insert, flushing the collection once a batch has accumulated.
     *
     * @param string $collection
     * @param array $document
     */
    public function insert(string $collection, array $document): void
    {
        $this->buffer[$collection][] = $document;
        if (count($this->buffer[$collection]) >= self::INSERT_BATCH) {
            $this->flushCollection($collection);
        }
    }

    /**
     * Read one document, or null if none matches.
     *
     * Flushes pending inserts first so a document written this run is visible.
     *
     * @param string $collection
     * @param array $filter
     * @return array|null
     */
    public function findOne(string $collection, array $filter): ?array
    {
        $this->flush();
        $document = $this->collection($collection)
            ->findOne($filter, ['typeMap' => ['root' => 'array']]);

        return ($document === null) ? null : (array)$document;
    }

    /**
     * Apply one update, optionally creating the document if none matches.
     *
     * Flushes pending inserts first so a document written this run is there to update.
     *
     * @param string $collection
     * @param array $filter
     * @param array $update A Mongo update document (e.g. `['$set' => [...]]`).
     * @param bool $upsert
     */
    public function updateOne(string $collection, array $filter, array $update, bool $upsert = false): void
    {
        $this->flush();
        $this->collection($collection)->updateOne($filter, $update, ['upsert' => $upsert]);
    }

    /**
     * Apply many updates in batched, unordered passes.
     *
     * Flushes pending inserts first, then batches to avoid a round-trip per operation.
     *
     * @param string $collection
     * @param array $operations List of `['updateOne' => [$filter, $update, $options]]`.
     */
    public function bulkWrite(string $collection, array $operations): void
    {
        $this->flush();
        $batch = [];
        foreach ($operations as $operation) {
            $batch[] = $operation;
            if (count($batch) >= self::INSERT_BATCH) {
                $this->collection($collection)->bulkWrite($batch, ['ordered' => false]);
                $batch = [];
            }
        }
        if (!empty($batch)) {
            $this->collection($collection)->bulkWrite($batch, ['ordered' => false]);
        }
    }

    /**
     * Drop a collection so a fresh migration starts from a clean slate.
     *
     * @param string $collection
     */
    public function drop(string $collection): void
    {
        $this->collection($collection)->drop();
    }

    /**
     * Create an index on a collection.
     *
     * @param string $collection
     * @param array $key Field => direction (e.g. `['name' => 1]`).
     * @param array $options
     */
    public function createIndex(string $collection, array $key, array $options = []): void
    {
        $this->collection($collection)->createIndex($key, $options);
    }

    /**
     * Write any buffered documents to their collections.
     */
    public function flush(): void
    {
        foreach (array_keys($this->buffer) as $collection) {
            $this->flushCollection($collection);
        }
    }

    /**
     * Insert and clear the buffer for a single collection.
     *
     * @param string $collection
     */
    protected function flushCollection(string $collection): void
    {
        if (empty($this->buffer[$collection])) {
            return;
        }
        $this->collection($collection)->insertMany($this->buffer[$collection]);
        $this->buffer[$collection] = [];
    }

    /**
     * @param string $name
     * @return Collection
     */
    protected function collection(string $name): Collection
    {
        return $this->getHandle()->getCollection($name);
    }

    /**
     * No schema to prepare; collections are created on first write.
     *
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
        $this->flush();
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
}
