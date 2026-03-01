<?php

declare(strict_types=1);

namespace NMS\Core\Models\Infrastructure;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\Collection;
use NMS\Core\Database\MongoDB;

/**
 * SiteManager
 *
 * CRUD for the `sites` collection.
 * Handles geospatial coordinates and stats aggregation.
 */
class SiteManager extends Collection
{
    public function __construct()
    {
        parent::__construct('sites');
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];

        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $filter['type'] = $filters['type'];
        }
        if (!empty($filters['search'])) {
            $filter['$or'] = [
                ['name' => ['$regex' => $filters['search'], '$options' => 'i']],
                ['code' => ['$regex' => $filters['search'], '$options' => 'i']],
            ];
        }

        return $this->paginate($filter, $page, $perPage, ['sort' => ['name' => 1]]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function create(array $data): string
    {
        $doc = [
            'name'        => $data['name'],
            'code'        => strtoupper($data['code']),
            'type'        => $data['type'] ?? 'datacenter',
            'address'     => [
                'street'      => $data['address']['street'] ?? null,
                'city'        => $data['address']['city'] ?? null,
                'country'     => $data['address']['country'] ?? null,
                'postal_code' => $data['address']['postal_code'] ?? null,
                'coordinates' => $this->buildCoordinates($data['address']['coordinates'] ?? null),
            ],
            'provider'    => $data['provider'] ?? null,
            'contract_id' => $data['contract_id'] ?? null,
            'stats'       => [
                'total_racks'        => 0,
                'total_devices'      => 0,
                'total_ports'        => 0,
                'active_connections' => 0,
            ],
            'uplinks'     => $data['uplinks'] ?? [],
            'contacts'    => $data['contacts'] ?? [],
            'notes'       => $data['notes'] ?? null,
            'status'      => $data['status'] ?? 'active',
        ];

        return $this->insertOne($doc);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(string $id, array $data): bool
    {
        $set = [];

        $scalarFields = ['name', 'code', 'type', 'provider', 'contract_id', 'notes', 'status'];
        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $field === 'code' ? strtoupper($data[$field]) : $data[$field];
            }
        }

        if (isset($data['address'])) {
            $addr = $data['address'];
            $addressFields = ['street', 'city', 'country', 'postal_code'];
            foreach ($addressFields as $field) {
                if (array_key_exists($field, $addr)) {
                    $set["address.$field"] = $addr[$field];
                }
            }
            if (isset($addr['coordinates'])) {
                $set['address.coordinates'] = $this->buildCoordinates($addr['coordinates']);
            }
        }

        if (array_key_exists('uplinks', $data)) {
            $set['uplinks'] = $data['uplinks'];
        }
        if (array_key_exists('contacts', $data)) {
            $set['contacts'] = $data['contacts'];
        }

        if (empty($set)) {
            return false;
        }

        return $this->updateById($id, ['$set' => $set]) > 0;
    }

    // ─── Stats ────────────────────────────────────────────────────────────────

    /**
     * Recompute and persist stats for a site.
     * Called after rack/device changes.
     */
    public function refreshStats(string $siteId): void
    {
        try {
            $db        = MongoDB::getInstance();
            $siteOid   = new ObjectId($siteId);

            $totalRacks   = $db->selectCollection('racks')
                ->countDocuments(['site_id' => $siteOid]);

            $totalDevices = $db->selectCollection('devices')
                ->countDocuments(['location.site_id' => $siteOid]);

            // Count cables touching this site
            $activeConns = $db->selectCollection('cables')
                ->countDocuments([
                    '$or' => [
                        ['endpoint_a.site_id' => $siteOid],
                        ['endpoint_b.site_id' => $siteOid],
                    ],
                    'status' => 'active',
                ]);

            $this->updateById($siteId, [
                '$set' => [
                    'stats.total_racks'        => $totalRacks,
                    'stats.total_devices'      => $totalDevices,
                    'stats.active_connections' => $activeConns,
                ],
            ]);
        } catch (\Throwable) {
            // Best-effort — don't fail on stats refresh error
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function buildCoordinates(?array $coords): ?array
    {
        if (!$coords || !isset($coords['lat'], $coords['lng'])) {
            return null;
        }
        return [
            'lat' => (float)$coords['lat'],
            'lng' => (float)$coords['lng'],
        ];
    }
}
