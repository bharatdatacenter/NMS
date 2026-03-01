<?php

declare(strict_types=1);

namespace NMS\Core\Models\Topology;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;

/**
 * PathMaterializer
 *
 * Computes and stores end-to-end cable paths in the `connectivity_paths` collection.
 *
 * Walk algorithm:
 *  1. Start from (deviceId, portName)
 *  2. Find a cable connecting to this port
 *  3. Follow to the other endpoint
 *  4. If the other endpoint is a patch panel: automatically traverse through
 *     (front port → rear port, or rear port → front port) and recurse
 *  5. Stop when we reach a non-patch-panel device or max depth (6)
 *
 * Path invalidation: when a cable changes, mark all paths using it as valid=false.
 */
class PathMaterializer
{
    private const MAX_DEPTH = 6;

    private \MongoDB\Collection $pathsCollection;
    private \MongoDB\Collection $cablesCollection;
    private \MongoDB\Collection $devicesCollection;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->pathsCollection   = $db->selectCollection('connectivity_paths');
        $this->cablesCollection  = $db->selectCollection('cables');
        $this->devicesCollection = $db->selectCollection('devices');
    }

    // ─── Materialize ──────────────────────────────────────────────────────────

    /**
     * Compute and store the path starting from (deviceId, portName).
     * Returns existing valid path if already computed, otherwise walks the graph.
     */
    public function materialize(string $deviceId, string $portName): array
    {
        // Check for existing valid path
        $existing = $this->pathsCollection->findOne([
            'source.device_id' => new ObjectId($deviceId),
            'source.port_name' => $portName,
            'valid'            => true,
        ]);

        if ($existing !== null) {
            return $this->bsonToArray($existing);
        }

        // Walk the cable graph
        $hops        = [];
        $cableIds    = [];
        $totalLength = 0.0;
        $visited     = [];

        $sourceDevice = $this->devicesCollection->findOne(['_id' => new ObjectId($deviceId)]);
        $sourceName   = $sourceDevice ? ($sourceDevice['name'] ?? '') : '';

        // Add source as first hop
        $hops[] = [
            'device_id'   => new ObjectId($deviceId),
            'device_name' => $sourceName,
            'port_out'    => $portName,
            'cable_id'    => null,
        ];

        $this->walkGraph($deviceId, $portName, $hops, $cableIds, $totalLength, $visited, 0);

        // Need at least 2 hops to have a valid path (source + destination)
        if (count($hops) < 2) {
            return [];
        }

        $lastHop = end($hops);
        $destDeviceId = $lastHop['device_id'];
        $destDevice   = $this->devicesCollection->findOne(['_id' => $destDeviceId]);

        $pathDoc = [
            'source' => [
                'device_id'   => new ObjectId($deviceId),
                'device_name' => $sourceName,
                'port_name'   => $portName,
            ],
            'destination' => [
                'device_id'   => $destDeviceId,
                'device_name' => $destDevice ? ($destDevice['name'] ?? '') : '',
                'port_name'   => $lastHop['port_in'] ?? '',
            ],
            'hops'                     => $hops,
            'hop_count'                => count($hops) - 1,
            'total_cable_length_meters'=> $totalLength,
            'cable_ids'                => $cableIds,
            'computed_at'              => new UTCDateTime(),
            'valid'                    => true,
        ];

        // Upsert path document
        $this->pathsCollection->updateOne(
            [
                'source.device_id' => new ObjectId($deviceId),
                'source.port_name' => $portName,
            ],
            ['$set' => $pathDoc],
            ['upsert' => true]
        );

        return $this->bsonToArray($pathDoc);
    }

    // ─── Invalidation ─────────────────────────────────────────────────────────

    /**
     * Mark all connectivity_paths using this cable as valid=false.
     * Called whenever a cable is created, updated, or deleted.
     *
     * @return int Number of paths invalidated
     */
    public function invalidateForCable(string $cableId): int
    {
        $result = $this->pathsCollection->updateMany(
            ['cable_ids' => $cableId],
            ['$set' => ['valid' => false]]
        );
        return $result->getModifiedCount();
    }

    /**
     * Get all paths that use a given cable (impact analysis).
     */
    public function getImpactedPaths(string $cableId): array
    {
        $cursor = $this->pathsCollection->find(['cable_ids' => $cableId]);
        $paths  = [];
        foreach ($cursor as $doc) {
            $paths[] = $this->bsonToArray($doc);
        }
        return $paths;
    }

    /**
     * Get connectivity path for a (deviceId, portName) source.
     * Falls back to $graphLookup when no valid pre-computed path exists.
     */
    public function getPath(string $deviceId, string $portName): array
    {
        // Try pre-computed path first
        $path = $this->pathsCollection->findOne([
            'source.device_id' => new ObjectId($deviceId),
            'source.port_name' => $portName,
            'valid'            => true,
        ]);

        if ($path !== null) {
            return $this->bsonToArray($path);
        }

        // Fallback: materialize on-demand
        return $this->materialize($deviceId, $portName);
    }

    // ─── Graph walk ───────────────────────────────────────────────────────────

    private function walkGraph(
        string $deviceId,
        string $portName,
        array  &$hops,
        array  &$cableIds,
        float  &$totalLength,
        array  &$visited,
        int    $depth
    ): void {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        $visitKey = "$deviceId:$portName";
        if (in_array($visitKey, $visited, true)) {
            return; // Cycle protection
        }
        $visited[] = $visitKey;

        // Find a cable connecting to this (device, port)
        $deviceOid = new ObjectId($deviceId);
        $cable = $this->cablesCollection->findOne([
            '$or' => [
                ['endpoint_a.device_id' => $deviceOid, 'endpoint_a.port_name' => $portName],
                ['endpoint_b.device_id' => $deviceOid, 'endpoint_b.port_name' => $portName],
            ],
            'status' => ['$ne' => 'decommissioned'],
        ]);

        if ($cable === null) {
            return; // Dead end
        }

        $cableLabel = $cable['cable_id'] ?? (string)$cable['_id'];
        $cableIds[] = $cableLabel;
        $totalLength += (float)($cable['length_meters'] ?? 0);

        // Determine the "other" endpoint
        $epA = $cable['endpoint_a'];
        $epB = $cable['endpoint_b'];

        $epADeviceId = (string)$epA['device_id'];
        $epAPort     = (string)($epA['port_name'] ?? '');
        $epBDeviceId = (string)$epB['device_id'];
        $epBPort     = (string)($epB['port_name'] ?? '');

        if ($epADeviceId === $deviceId && $epAPort === $portName) {
            $nextDeviceId = $epBDeviceId;
            $nextPortName = $epBPort;
        } else {
            $nextDeviceId = $epADeviceId;
            $nextPortName = $epAPort;
        }

        // Update the cable_id on the source hop (last hop added)
        $lastIdx = count($hops) - 1;
        $hops[$lastIdx]['cable_id'] = $cableLabel;

        // Fetch next device to see if it's a patch panel
        $nextDevice = $this->devicesCollection->findOne(['_id' => new ObjectId($nextDeviceId)]);
        $nextName   = $nextDevice ? ($nextDevice['name'] ?? '') : '';
        $nextRole   = $nextDevice ? ($nextDevice['role'] ?? '') : '';

        if ($nextRole === 'patch_panel') {
            // Traverse through patch panel: find the corresponding exit port
            $exitPort = $this->getPatchPanelExitPort($nextDeviceId, $nextPortName, $nextDevice);

            // Add patch panel as intermediate hop
            $hops[] = [
                'device_id'   => new ObjectId($nextDeviceId),
                'device_name' => $nextName,
                'port_in'     => $nextPortName,
                'port_out'    => $exitPort,
                'cable_id'    => null, // Will be set on next recursion
            ];

            // Continue walk from patch panel's exit port
            $this->walkGraph($nextDeviceId, $exitPort, $hops, $cableIds, $totalLength, $visited, $depth + 1);
        } else {
            // Terminal hop — destination device
            $hops[] = [
                'device_id'   => new ObjectId($nextDeviceId),
                'device_name' => $nextName,
                'port_in'     => $nextPortName,
                'cable_id'    => null,
            ];
        }
    }

    /**
     * Determine the exit port on a patch panel given the entry port.
     * Patch panel ports follow F-xx / R-xx convention (front/rear).
     * Falls back to scanning device ports array for explicit front_label / rear_label.
     */
    private function getPatchPanelExitPort(string $deviceId, string $entryPort, mixed $deviceDoc): string
    {
        // Try convention-based matching first (F-xx <-> R-xx)
        if (preg_match('/^F-(.+)$/i', $entryPort, $m)) {
            return 'R-' . $m[1];
        }
        if (preg_match('/^R-(.+)$/i', $entryPort, $m)) {
            return 'F-' . $m[1];
        }

        // Scan device ports for explicit front_label / rear_label mapping
        if ($deviceDoc !== null) {
            $ports = $deviceDoc['ports'] ?? [];
            foreach ($ports as $port) {
                $frontLabel = (string)($port['front_label'] ?? '');
                $rearLabel  = (string)($port['rear_label'] ?? '');
                if ($frontLabel === $entryPort) {
                    return $rearLabel ?: $entryPort;
                }
                if ($rearLabel === $entryPort) {
                    return $frontLabel ?: $entryPort;
                }
            }
        }

        // No matching found — return same port (pass-through)
        return $entryPort;
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function bsonToArray(mixed $doc): array
    {
        if ($doc === null) {
            return [];
        }
        $arr = json_decode(json_encode($doc), true);
        if (isset($arr['_id']['$oid'])) {
            $arr['id'] = $arr['_id']['$oid'];
            unset($arr['_id']);
        }
        return $arr;
    }
}
