<?php

declare(strict_types=1);

namespace NMS\Core\Models\Ipam;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\IPUtils;
use NMS\Core\Models\Devices\DeviceInterface;

/**
 * ConflictChecker
 *
 * Checks IP conflicts within NMS IPAM and against BGP-learned routes on devices.
 */
class ConflictChecker
{
    private \MongoDB\Collection $assignments;
    private \MongoDB\Collection $reservations;
    private \MongoDB\Collection $pools;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->assignments  = $db->selectCollection('ip_assignments');
        $this->reservations = $db->selectCollection('ip_reservations');
        $this->pools        = $db->selectCollection('ip_pools');
    }

    /**
     * Check if a specific IP is already assigned or reserved within a pool.
     * Returns true if a conflict exists (IP is in use).
     */
    public function checkConflict(string $ip, string $poolId): bool
    {
        $poolOid = new ObjectId($poolId);

        // Active, reserved, or quarantined assignment
        $existing = $this->assignments->findOne([
            'ip_address' => $ip,
            'pool_id'    => $poolOid,
            'status'     => ['$in' => ['active', 'reserved', 'quarantine']],
        ]);
        if ($existing !== null) {
            return true;
        }

        // Explicit reservation
        $reserved = $this->reservations->findOne([
            'ip_address' => $ip,
            'pool_id'    => $poolOid,
        ]);
        return $reserved !== null;
    }

    /**
     * Check if any BGP-learned routes on a device overlap with the given CIDR.
     * Calls adapter->getBGPPrefixesForRange() — a targeted query, not a full table dump.
     *
     * Returns array of conflicting prefix strings (empty array = no conflict).
     * Failure to query the device (unsupported adapter, timeout) returns empty — does not block provisioning.
     */
    public function checkBGPConflict(string $cidr, DeviceInterface $adapter): array
    {
        try {
            return $adapter->getBGPPrefixesForRange($cidr);
        } catch (\Throwable) {
            // Non-fatal: if we can't reach the device for BGP check, return empty
            return [];
        }
    }

    /**
     * Check if a new pool CIDR overlaps with any existing pool in the same block.
     *
     * @param string      $cidr           The proposed pool network
     * @param string      $blockId        The parent block ID
     * @param string|null $excludePoolId  Exclude this pool ID (for update checks)
     */
    public function checkPoolOverlap(string $cidr, string $blockId, ?string $excludePoolId = null): bool
    {
        $calc   = new SubnetCalculator();
        $cursor = $this->pools->find([
            'block_id' => new ObjectId($blockId),
            'status'   => ['$ne' => 'deprecated'],
        ]);

        foreach ($cursor as $pool) {
            $existingId = (string)($pool['_id'] ?? '');
            if ($excludePoolId !== null && $existingId === $excludePoolId) {
                continue;
            }
            if ($calc->cidrsOverlap($cidr, (string)($pool['network'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an IP is within the valid range of a pool.
     */
    public function isInPool(string $ip, array $pool): bool
    {
        return IPUtils::networkContains($pool['network'], $ip);
    }
}
