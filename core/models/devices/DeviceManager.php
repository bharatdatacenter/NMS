<?php

declare(strict_types=1);

namespace NMS\Core\Models\Devices;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\Collection;

/**
 * DeviceManager
 *
 * CRUD for the `devices` collection.
 * Phase 2 scope: database operations only — no vendor connections (Phase 3).
 * Handles regular devices and patch panel devices (role: "patch_panel").
 */
class DeviceManager extends Collection
{
    public function __construct()
    {
        parent::__construct('devices');
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    /**
     * List devices with optional filters.
     *
     * @param array $filters  Keys: site_id, rack_id, role, vendor, status, search
     * @param int   $page
     * @param int   $perPage
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];

        if (!empty($filters['site_id'])) {
            $filter['location.site_id'] = new ObjectId($filters['site_id']);
        }
        if (!empty($filters['rack_id'])) {
            $filter['location.rack_id'] = new ObjectId($filters['rack_id']);
        }
        if (!empty($filters['role'])) {
            $filter['role'] = $filters['role'];
        }
        if (!empty($filters['vendor'])) {
            $filter['vendor'] = $filters['vendor'];
        }
        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $filter['$or'] = [
                ['name'     => ['$regex' => $filters['search'], '$options' => 'i']],
                ['hostname' => ['$regex' => $filters['search'], '$options' => 'i']],
                ['tags'     => $filters['search']],
            ];
        }

        return $this->paginate($filter, $page, $perPage, ['sort' => ['name' => 1]]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Create a new device.
     * Supports regular devices and patch panels (role: "patch_panel").
     */
    public function create(array $data, string $createdBy): string
    {
        $doc = [
            'name'             => $data['name'],
            'hostname'         => $data['hostname'] ?? null,
            'ip_address'       => $data['ip_address'] ?? null,
            'vendor'           => $data['vendor'] ?? 'generic',
            'model'            => $data['model'] ?? null,
            'serial_number'    => $data['serial_number'] ?? null,
            'firmware_version' => $data['firmware_version'] ?? null,

            'location' => [
                'site_id'   => isset($data['site_id'])   ? new ObjectId($data['site_id'])   : null,
                'site_name' => $data['site_name'] ?? null,
                'building'  => $data['building'] ?? null,
                'floor'     => $data['floor'] ?? null,
                'room'      => $data['room'] ?? null,
                'rack_id'   => isset($data['rack_id'])   ? new ObjectId($data['rack_id'])   : null,
                'rack_name' => $data['rack_name'] ?? null,
                'rack_unit' => $data['rack_unit'] ?? null,
                'rack_side' => $data['rack_side'] ?? 'front',
            ],

            'role'   => $data['role'] ?? 'access_switch',
            'status' => $data['status'] ?? 'unknown',

            'last_seen'   => null,
            'last_backup' => null,

            'cluster_id'   => null,
            'cluster_role' => null,

            'drift' => [
                'status'           => 'unknown',
                'last_checked'     => null,
                'last_drifted'     => null,
                'open_drift_count' => 0,
            ],

            'circuit_breaker' => [
                'state'               => 'closed',
                'consecutive_failures'=> 0,
                'last_failure'        => null,
                'cooldown_until'      => null,
            ],

            'ports' => $data['ports'] ?? [],

            'zabbix' => [
                'host_id'     => null,
                'host_name'   => null,
                'last_synced' => null,
                'auto_import' => false,
            ],

            'notes' => $data['notes'] ?? null,
            'tags'  => $data['tags'] ?? [],

            'created_by' => new ObjectId($createdBy),
        ];

        $id = $this->insertOne($doc);

        // Sync rack's installed_devices list
        if (!empty($data['rack_id'])) {
            $this->syncRackDevice($data['rack_id'], $id, $doc);
        }

        return $id;
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    /**
     * Update a device by ID.
     */
    public function update(string $id, array $data): bool
    {
        $set = [];

        $scalarFields = [
            'name', 'hostname', 'ip_address', 'vendor', 'model',
            'serial_number', 'firmware_version', 'role', 'status', 'notes',
        ];
        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }

        if (array_key_exists('tags', $data)) {
            $set['tags'] = $data['tags'];
        }
        if (array_key_exists('ports', $data)) {
            $set['ports'] = $data['ports'];
        }

        // Location updates
        $locationFields = ['site_id', 'site_name', 'building', 'floor', 'room', 'rack_id', 'rack_name', 'rack_unit', 'rack_side'];
        foreach ($locationFields as $field) {
            if (array_key_exists($field, $data)) {
                $val = in_array($field, ['site_id', 'rack_id']) && $data[$field] !== null
                    ? new ObjectId($data[$field])
                    : $data[$field];
                $set["location.$field"] = $val;
            }
        }

        if (empty($set)) {
            return false;
        }

        $modified = $this->updateById($id, ['$set' => $set]);

        // Re-sync rack if location changed
        if (isset($data['rack_id'])) {
            $device = $this->findById($id);
            if ($device) {
                $this->syncRackDevice($data['rack_id'], $id, $device);
            }
        }

        return $modified > 0;
    }

    // ─── Ports ────────────────────────────────────────────────────────────────

    /**
     * Get the ports array for a device.
     */
    public function getPorts(string $id): array
    {
        $device = $this->findById($id);
        return $device['ports'] ?? [];
    }

    /**
     * Update the circuit breaker state on a device document.
     * Called by VendorAdapter on state changes.
     */
    public function updateCircuitBreaker(string $deviceId, array $cbState): void
    {
        $this->updateById($deviceId, [
            '$set' => [
                'circuit_breaker' => $cbState,
                'status' => $cbState['state'] === 'open' ? 'unreachable' : 'online',
            ],
        ]);
    }

    // ─── Rack sync ────────────────────────────────────────────────────────────

    /**
     * Sync installed_devices entry in the rack when a device is placed.
     * Also updates rack utilization.
     */
    public function syncRackDevice(string $rackId, string $deviceId, array $deviceDoc): void
    {
        try {
            $rackOid    = new ObjectId($rackId);
            $deviceOid  = new ObjectId($deviceId);

            $entry = [
                'device_id'        => $deviceOid,
                'device_name'      => $deviceDoc['name'] ?? '',
                'device_type'      => $deviceDoc['role'] ?? 'unknown',
                'vendor'           => $deviceDoc['vendor'] ?? 'generic',
                'rack_unit_start'  => $deviceDoc['location']['rack_unit'] ?? null,
                'rack_unit_end'    => $deviceDoc['location']['rack_unit'] ?? null,
                'side'             => $deviceDoc['location']['rack_side'] ?? 'front',
                'power_draw_watts' => $deviceDoc['power_draw_watts'] ?? 0,
            ];

            // Remove old entry (if any) then push new one
            $rackCollection = \NMS\Core\Database\MongoDB::getInstance()
                ->selectCollection('racks');

            $rackCollection->updateOne(
                ['_id' => $rackOid],
                ['$pull' => ['installed_devices' => ['device_id' => $deviceOid]]]
            );
            $rackCollection->updateOne(
                ['_id' => $rackOid],
                ['$push' => ['installed_devices' => $entry]]
            );
        } catch (\Throwable) {
            // Best-effort sync — don't fail device creation on rack sync error
        }
    }

    /**
     * Remove a device entry from its rack's installed_devices list.
     */
    public function removeFromRack(string $deviceId): void
    {
        try {
            $deviceOid = new ObjectId($deviceId);
            \NMS\Core\Database\MongoDB::getInstance()
                ->selectCollection('racks')
                ->updateMany(
                    [],
                    ['$pull' => ['installed_devices' => ['device_id' => $deviceOid]]]
                );
        } catch (\Throwable) {}
    }
}
