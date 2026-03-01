<?php

declare(strict_types=1);

namespace NMS\Core\Database;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection as MongoCollection;
use MongoDB\Model\BSONDocument;

/**
 * Base collection class with standard CRUD helpers.
 * All model managers extend this.
 */
abstract class Collection
{
    protected MongoCollection $collection;

    public function __construct(string $collectionName, ?MongoDB $db = null)
    {
        $db ??= MongoDB::getInstance();
        $this->collection = $db->selectCollection($collectionName);
    }

    /**
     * Find a single document by its _id.
     */
    public function findById(string|ObjectId $id): ?array
    {
        $oid = $id instanceof ObjectId ? $id : new ObjectId($id);
        $doc = $this->collection->findOne(['_id' => $oid]);
        return $doc ? $this->toArray($doc) : null;
    }

    /**
     * Find all documents matching a filter.
     */
    public function findAll(array $filter = [], array $options = []): array
    {
        $cursor = $this->collection->find($filter, $options);
        return array_map([$this, 'toArray'], $cursor->toArray());
    }

    /**
     * Find one document matching a filter.
     */
    public function findOne(array $filter, array $options = []): ?array
    {
        $doc = $this->collection->findOne($filter, $options);
        return $doc ? $this->toArray($doc) : null;
    }

    /**
     * Insert a document. Returns the inserted _id as string.
     */
    public function insertOne(array $document): string
    {
        $document['created_at'] = new \MongoDB\BSON\UTCDateTime();
        $document['updated_at'] = new \MongoDB\BSON\UTCDateTime();
        $result = $this->collection->insertOne($document);
        return (string)$result->getInsertedId();
    }

    /**
     * Update a document by _id. Returns number of modified documents.
     */
    public function updateById(string|ObjectId $id, array $update): int
    {
        $oid = $id instanceof ObjectId ? $id : new ObjectId($id);
        $update['$set']['updated_at'] = new \MongoDB\BSON\UTCDateTime();
        $result = $this->collection->updateOne(['_id' => $oid], $update);
        return $result->getModifiedCount();
    }

    /**
     * Update documents matching filter.
     */
    public function updateMany(array $filter, array $update): int
    {
        $update['$set']['updated_at'] = new \MongoDB\BSON\UTCDateTime();
        $result = $this->collection->updateMany($filter, $update);
        return $result->getModifiedCount();
    }

    /**
     * Delete a document by _id.
     */
    public function deleteById(string|ObjectId $id): bool
    {
        $oid = $id instanceof ObjectId ? $id : new ObjectId($id);
        $result = $this->collection->deleteOne(['_id' => $oid]);
        return $result->getDeletedCount() > 0;
    }

    /**
     * Count documents matching filter.
     */
    public function count(array $filter = []): int
    {
        return $this->collection->countDocuments($filter);
    }

    /**
     * Atomic findOneAndUpdate — for race-condition-safe operations (e.g. IP allocation).
     */
    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?array
    {
        $options['returnDocument'] = \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER;
        $doc = $this->collection->findOneAndUpdate($filter, $update, $options);
        return $doc ? $this->toArray($doc) : null;
    }

    /**
     * Paginated list helper.
     */
    public function paginate(array $filter = [], int $page = 1, int $perPage = 50, array $options = []): array
    {
        $page = max(1, $page);
        $skip = ($page - 1) * $perPage;
        $total = $this->count($filter);

        $options['skip'] = $skip;
        $options['limit'] = $perPage;
        $items = $this->findAll($filter, $options);

        return [
            'data'       => $items,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'last_page'  => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Convert BSON document to plain PHP array.
     */
    protected function toArray(mixed $doc): array
    {
        if ($doc === null) {
            return [];
        }
        $arr = json_decode(json_encode($doc), true);
        // Normalize _id to string
        if (isset($arr['_id']['$oid'])) {
            $arr['id'] = $arr['_id']['$oid'];
            unset($arr['_id']);
        } elseif (isset($arr['_id'])) {
            $arr['id'] = (string)$arr['_id'];
            unset($arr['_id']);
        }
        return $arr;
    }

    public function getCollection(): MongoCollection
    {
        return $this->collection;
    }
}
