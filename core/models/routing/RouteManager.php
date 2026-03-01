<?php

declare(strict_types=1);

namespace NMS\Core\Models\Routing;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;

/**
 * RouteManager
 *
 * CRUD for the `static_routes` collection.
 * ip_version is required on all records.
 * Writes route_history on every create/delete.
 */
class RouteManager
{
    private \MongoDB\Collection $routes;
    private \MongoDB\Collection $history;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->routes  = $db->selectCollection('static_routes');
        $this->history = $db->selectCollection('route_history');
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];
        if (!empty($filters['ip_version'])) {
            $filter['ip_version'] = $filters['ip_version'];
        }
        if (!empty($filters['device_id'])) {
            $filter['device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['cluster_id'])) {
            $filter['cluster_id'] = new ObjectId($filters['cluster_id']);
        }
        if (!empty($filters['route_type'])) {
            $filter['route_type'] = $filters['route_type'];
        }

        $total  = $this->routes->countDocuments($filter);
        $cursor = $this->routes->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['destination' => 1],
        ]);

        return [
            'data'      => iterator_to_array($cursor, false),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function create(array $data, string $createdBy): string
    {
        if (empty($data['ip_version'])) {
            throw new \InvalidArgumentException('ip_version is required');
        }
        if (empty($data['destination'])) {
            throw new \InvalidArgumentException('destination CIDR is required');
        }
        if (empty($data['device_id'])) {
            throw new \InvalidArgumentException('device_id is required');
        }

        $now = new UTCDateTime();
        $doc = [
            'device_id'        => new ObjectId($data['device_id']),
            'cluster_id'       => isset($data['cluster_id']) ? new ObjectId($data['cluster_id']) : null,
            'ip_version'       => $data['ip_version'],
            'destination'      => $data['destination'],
            'gateway'          => $data['gateway'] ?? null,
            'interface_name'   => $data['interface_name'] ?? null,
            'distance'         => (int) ($data['distance'] ?? 1),
            'metric'           => (int) ($data['metric'] ?? 0),
            'route_type'       => $data['route_type'] ?? 'host',
            'ip_assignment_id' => isset($data['ip_assignment_id']) ? new ObjectId($data['ip_assignment_id']) : null,
            'purpose'          => $data['purpose'] ?? null,
            'synced_to_device' => false,
            'device_route_id'  => null,
            'last_synced'      => null,
            'sync_error'       => null,
            'enabled'          => true,
            'created_at'       => $now,
            'updated_at'       => $now,
            'created_by'       => new ObjectId($createdBy),
        ];

        $result = $this->routes->insertOne($doc);
        $id     = (string) $result->getInsertedId();

        $this->writeHistory($id, $data['device_id'], 'created', null, $doc, $createdBy, $data['purpose'] ?? null);

        return $id;
    }

    // ─── Find ─────────────────────────────────────────────────────────────────

    public function findById(string $id): ?array
    {
        $doc = $this->routes->findOne(['_id' => new ObjectId($id)]);
        return $doc ? iterator_to_array($doc) : null;
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function delete(string $id, string $deletedBy): bool
    {
        $doc = $this->findById($id);
        if (!$doc) {
            return false;
        }

        $this->routes->deleteOne(['_id' => new ObjectId($id)]);
        $this->writeHistory($id, (string) $doc['device_id'], 'deleted', $doc, null, $deletedBy, null);

        return true;
    }

    // ─── Sync Status ──────────────────────────────────────────────────────────

    public function updateSyncStatus(string $id, bool $synced, ?string $deviceRouteId = null, ?string $error = null): void
    {
        $update = [
            'synced_to_device' => $synced,
            'last_synced'      => new UTCDateTime(),
            'sync_error'       => $error,
            'updated_at'       => new UTCDateTime(),
        ];
        if ($deviceRouteId !== null) {
            $update['device_route_id'] = $deviceRouteId;
        }

        $this->routes->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $update]
        );
    }

    // ─── Device Routes ────────────────────────────────────────────────────────

    public function listByDevice(string $deviceId, int $page = 1, int $perPage = 100): array
    {
        return $this->list(['device_id' => $deviceId], $page, $perPage);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function writeHistory(
        string  $routeId,
        string  $deviceId,
        string  $action,
        ?array  $previousState,
        ?array  $newState,
        string  $changedBy,
        ?string $reason
    ): void {
        $this->history->insertOne([
            'route_id'       => new ObjectId($routeId),
            'device_id'      => new ObjectId($deviceId),
            'action'         => $action,
            'destination'    => $newState['destination'] ?? ($previousState['destination'] ?? null),
            'gateway'        => $newState['gateway']     ?? ($previousState['gateway']     ?? null),
            'previous_state' => $previousState,
            'new_state'      => $newState,
            'changed_by'     => new ObjectId($changedBy),
            'changed_at'     => new UTCDateTime(),
            'reason'         => $reason,
        ]);
    }
}
