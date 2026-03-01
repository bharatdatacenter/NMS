<?php

declare(strict_types=1);

namespace NMS\Core\Models\Infrastructure;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\Collection;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Topology\PathMaterializer;

/**
 * CableManager
 *
 * CRUD for the `cables` collection.
 * Validates endpoints on create/update, triggers path invalidation on any change.
 */
class CableManager extends Collection
{
    public function __construct()
    {
        parent::__construct('cables');
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];

        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }
        if (!empty($filters['cable_type'])) {
            $filter['cable_type'] = $filters['cable_type'];
        }

        return $this->paginate($filter, $page, $perPage, ['sort' => ['cable_id' => 1]]);
    }

    /**
     * Get all cables connected to a specific device.
     */
    public function listForDevice(string $deviceId): array
    {
        $oid = new ObjectId($deviceId);
        $filter = [
            '$or' => [
                ['endpoint_a.device_id' => $oid],
                ['endpoint_b.device_id' => $oid],
            ],
        ];
        return $this->findAll($filter);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Register a new cable.
     *
     * @throws \InvalidArgumentException on validation failure
     */
    public function create(array $data, string $createdBy): string
    {
        $this->validateEndpoints($data['endpoint_a'], $data['endpoint_b']);

        $doc = [
            'cable_id'    => $data['cable_id'],  // Physical label on cable

            'endpoint_a'  => $this->buildEndpoint($data['endpoint_a']),
            'endpoint_b'  => $this->buildEndpoint($data['endpoint_b']),

            'cable_type'  => $data['cable_type'] ?? 'cat6a',
            'length_meters' => $data['length_meters'] ?? null,
            'color'       => $data['color'] ?? null,
            'connector_a' => $data['connector_a'] ?? 'RJ45',
            'connector_b' => $data['connector_b'] ?? 'RJ45',

            'status'       => $data['status'] ?? 'active',
            'installed_at' => isset($data['installed_at'])
                ? new \MongoDB\BSON\UTCDateTime(strtotime($data['installed_at']) * 1000)
                : new \MongoDB\BSON\UTCDateTime(),
            'installed_by' => new ObjectId($createdBy),
            'tested'       => $data['tested'] ?? false,
            'test_result'  => $data['test_result'] ?? null,

            'notes' => $data['notes'] ?? null,
        ];

        $id = $this->insertOne($doc);

        // Materialize paths now that this cable exists
        $this->materializePaths($doc['endpoint_a']['device_id'], $doc['endpoint_a']['port_name']);

        return $id;
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(string $id, array $data): bool
    {
        $existing = $this->findById($id);
        if (!$existing) {
            return false;
        }

        // Collect cable_ids from existing paths to invalidate before changing
        $this->invalidateExistingPaths($existing['cable_id'] ?? $id);

        $set = [];
        $scalarFields = ['cable_type', 'length_meters', 'color', 'connector_a', 'connector_b', 'status', 'tested', 'test_result', 'notes'];
        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }

        if (isset($data['endpoint_a'])) {
            $this->validateEndpoints($data['endpoint_a'], $data['endpoint_b'] ?? $existing['endpoint_b']);
            $set['endpoint_a'] = $this->buildEndpoint($data['endpoint_a']);
        }
        if (isset($data['endpoint_b'])) {
            $this->validateEndpoints($data['endpoint_a'] ?? $existing['endpoint_a'], $data['endpoint_b']);
            $set['endpoint_b'] = $this->buildEndpoint($data['endpoint_b']);
        }

        if (empty($set)) {
            return false;
        }

        $modified = $this->updateById($id, ['$set' => $set]);

        // Re-materialize from updated endpoint
        $endpointA = $set['endpoint_a'] ?? $existing['endpoint_a'];
        $this->materializePaths(
            is_array($endpointA['device_id']) ? ($endpointA['device_id']['$oid'] ?? '') : (string)$endpointA['device_id'],
            $endpointA['port_name']
        );

        return $modified > 0;
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function delete(string $id): bool
    {
        $cable = $this->findById($id);
        if (!$cable) {
            return false;
        }

        // Invalidate all paths using this cable before deleting
        $this->invalidateExistingPaths($cable['cable_id'] ?? $id);

        return $this->deleteById($id);
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    /**
     * Validate that both endpoints exist and the ports aren't already in use.
     *
     * @throws \InvalidArgumentException
     */
    public function validateEndpoints(array $endpointA, array $endpointB, ?string $excludeCableId = null): void
    {
        $db = MongoDB::getInstance();

        // Validate devices exist
        foreach ([['endpoint_a', $endpointA], ['endpoint_b', $endpointB]] as [$label, $ep]) {
            $deviceId = $ep['device_id'] ?? null;
            if (!$deviceId) {
                throw new \InvalidArgumentException("Missing device_id for $label");
            }
            $device = $db->selectCollection('devices')
                ->findOne(['_id' => new ObjectId($deviceId)]);
            if (!$device) {
                throw new \InvalidArgumentException("Device not found for $label: $deviceId");
            }
        }

        // Validate ports aren't already connected (excluding current cable on update)
        $this->checkPortAvailable($endpointA, $excludeCableId, 'endpoint_a');
        $this->checkPortAvailable($endpointB, $excludeCableId, 'endpoint_b');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function buildEndpoint(array $ep): array
    {
        return [
            'site_id'   => isset($ep['site_id'])  ? new ObjectId($ep['site_id'])  : null,
            'rack_id'   => isset($ep['rack_id'])  ? new ObjectId($ep['rack_id'])  : null,
            'rack_name' => $ep['rack_name'] ?? null,
            'device_id' => new ObjectId($ep['device_id']),
            'device_name' => $ep['device_name'] ?? null,
            'port_name' => $ep['port_name'],
        ];
    }

    private function checkPortAvailable(array $ep, ?string $excludeId, string $label): void
    {
        $deviceOid = new ObjectId($ep['device_id']);
        $portName  = $ep['port_name'];

        $filter = [
            '$or' => [
                ['endpoint_a.device_id' => $deviceOid, 'endpoint_a.port_name' => $portName],
                ['endpoint_b.device_id' => $deviceOid, 'endpoint_b.port_name' => $portName],
            ],
        ];

        // On update, exclude the cable being updated
        if ($excludeId !== null) {
            $filter['cable_id'] = ['$ne' => $excludeId];
        }

        $existing = $this->collection->findOne($filter);
        if ($existing) {
            throw new \InvalidArgumentException(
                "Port $portName on device {$ep['device_id']} is already connected ($label)"
            );
        }
    }

    private function invalidateExistingPaths(string $cableId): void
    {
        try {
            $pm = new PathMaterializer();
            $pm->invalidateForCable($cableId);
        } catch (\Throwable) {
            // Best-effort
        }
    }

    private function materializePaths(string $deviceId, string $portName): void
    {
        try {
            $pm = new PathMaterializer();
            $pm->materialize($deviceId, $portName);
        } catch (\Throwable) {
            // Best-effort — paths can be recomputed on demand via $graphLookup fallback
        }
    }
}
