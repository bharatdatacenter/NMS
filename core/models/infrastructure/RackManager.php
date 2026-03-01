<?php

declare(strict_types=1);

namespace NMS\Core\Models\Infrastructure;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\Collection;

/**
 * RackManager
 *
 * CRUD for the `racks` collection.
 * Manages U-slot occupancy, installed_devices sync, and utilization.
 */
class RackManager extends Collection
{
    public function __construct()
    {
        parent::__construct('racks');
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];

        if (!empty($filters['site_id'])) {
            $filter['site_id'] = new ObjectId($filters['site_id']);
        }
        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $filter['$or'] = [
                ['name'  => ['$regex' => $filters['search'], '$options' => 'i']],
                ['label' => ['$regex' => $filters['search'], '$options' => 'i']],
            ];
        }

        return $this->paginate($filter, $page, $perPage, ['sort' => ['name' => 1]]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function create(array $data): string
    {
        $totalUnits  = (int)($data['specs']['total_units'] ?? 42);
        $usableUnits = (int)($data['specs']['usable_units'] ?? $totalUnits - 2);

        $doc = [
            'site_id' => new ObjectId($data['site_id']),
            'name'    => $data['name'],
            'label'   => $data['label'] ?? $data['name'],

            'location' => [
                'building' => $data['location']['building'] ?? null,
                'floor'    => $data['location']['floor'] ?? null,
                'room'     => $data['location']['room'] ?? null,
                'row'      => $data['location']['row'] ?? null,
                'position' => isset($data['location']['position']) ? (int)$data['location']['position'] : null,
            ],

            'specs' => [
                'total_units'      => $totalUnits,
                'usable_units'     => $usableUnits,
                'width_inches'     => (int)($data['specs']['width_inches'] ?? 19),
                'depth_inches'     => (int)($data['specs']['depth_inches'] ?? 42),
                'max_power_watts'  => (int)($data['specs']['max_power_watts'] ?? 10000),
            ],

            'installed_devices' => [],

            'utilization' => [
                'used_units'           => 0,
                'available_units'      => $usableUnits,
                'power_used_watts'     => 0,
                'power_available_watts'=> (int)($data['specs']['max_power_watts'] ?? 10000),
            ],

            'notes'  => $data['notes'] ?? null,
            'status' => $data['status'] ?? 'active',
        ];

        return $this->insertOne($doc);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(string $id, array $data): bool
    {
        $set = [];

        $scalarFields = ['name', 'label', 'notes', 'status'];
        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }

        if (isset($data['location'])) {
            foreach (['building', 'floor', 'room', 'row', 'position'] as $f) {
                if (array_key_exists($f, $data['location'])) {
                    $set["location.$f"] = $data['location'][$f];
                }
            }
        }

        if (isset($data['specs'])) {
            foreach (['total_units', 'usable_units', 'width_inches', 'depth_inches', 'max_power_watts'] as $f) {
                if (array_key_exists($f, $data['specs'])) {
                    $set["specs.$f"] = (int)$data['specs'][$f];
                }
            }
        }

        if (empty($set)) {
            return false;
        }

        $modified = $this->updateById($id, ['$set' => $set]);

        // Recalculate utilization if specs changed
        if (isset($data['specs'])) {
            $this->recalculateUtilization($id);
        }

        return $modified > 0;
    }

    // ─── Diagram data ─────────────────────────────────────────────────────────

    /**
     * Returns structured data for a visual rack elevation diagram.
     * Front-end renders U positions with installed devices.
     */
    public function getDiagramData(string $id): ?array
    {
        $rack = $this->findById($id);
        if (!$rack) {
            return null;
        }

        $totalUnits = $rack['specs']['total_units'] ?? 42;

        // Build U-slot occupancy map
        $slots = array_fill(1, $totalUnits, null);
        foreach ($rack['installed_devices'] ?? [] as $device) {
            $start = (int)($device['rack_unit_start'] ?? 0);
            $end   = (int)($device['rack_unit_end'] ?? $start);
            for ($u = $start; $u <= $end; $u++) {
                if (isset($slots[$u])) {
                    $slots[$u] = [
                        'device_id'   => $device['device_id']['$oid'] ?? (string)$device['device_id'],
                        'device_name' => $device['device_name'],
                        'device_type' => $device['device_type'],
                        'vendor'      => $device['vendor'],
                        'u_start'     => $start,
                        'u_end'       => $end,
                        'side'        => $device['side'] ?? 'front',
                        'is_start'    => $u === $start,
                    ];
                }
            }
        }

        return [
            'rack'         => $rack,
            'total_units'  => $totalUnits,
            'slots'        => $slots,
            'utilization'  => $rack['utilization'] ?? [],
        ];
    }

    // ─── Utilization ──────────────────────────────────────────────────────────

    /**
     * Recalculate and persist utilization stats from installed_devices list.
     */
    public function recalculateUtilization(string $rackId): void
    {
        $rack = $this->findById($rackId);
        if (!$rack) {
            return;
        }

        $usedUnits  = 0;
        $powerUsed  = 0;
        foreach ($rack['installed_devices'] ?? [] as $device) {
            $start = (int)($device['rack_unit_start'] ?? 0);
            $end   = (int)($device['rack_unit_end'] ?? $start);
            $usedUnits += max(0, $end - $start + 1);
            $powerUsed += (int)($device['power_draw_watts'] ?? 0);
        }

        $usableUnits     = (int)($rack['specs']['usable_units'] ?? 40);
        $maxPower        = (int)($rack['specs']['max_power_watts'] ?? 10000);
        $availableUnits  = max(0, $usableUnits - $usedUnits);
        $availablePower  = max(0, $maxPower - $powerUsed);

        $this->updateById($rackId, [
            '$set' => [
                'utilization' => [
                    'used_units'            => $usedUnits,
                    'available_units'       => $availableUnits,
                    'power_used_watts'      => $powerUsed,
                    'power_available_watts' => $availablePower,
                ],
            ],
        ]);
    }
}
