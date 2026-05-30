<?php

declare(strict_types=1);

namespace NMS\Services\Scheduler;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Auth\ImsTicketClient;
use NMS\Core\Auth\JWTHelper;
use NMS\Core\Auth\M2MTokenHelper;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Drift\DriftDetector;
use NMS\Core\Models\Notifications\NotificationManager;
use NMS\Core\Models\Notifications\NotificationMessage;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\VaultSecretsManager;

/**
 * DriftScanner
 *
 * Poll cadence:
 * - edge_firewall: 5 minutes
 * - core_router: 10 minutes
 * - access_switch: 30 minutes
 * - offline/unreachable: skipped
 */
class DriftScanner
{
    private const ROLE_INTERVALS_MINUTES = [
        'edge_firewall' => 5,
        'core_router' => 10,
        'access_switch' => 30,
    ];

    private DriftDetector $detector;
    private \MongoDB\Collection $devices;
    private \MongoDB\Collection $driftLog;
    private \MongoDB\Collection $ticketRefs;
    private ?NotificationManager $notifier;

    public function __construct(?DriftDetector $detector = null, ?NotificationManager $notifier = null)
    {
        $db = MongoDB::getInstance();
        $this->detector = $detector ?? new DriftDetector();
        $this->devices = $db->selectCollection('devices');
        $this->driftLog = $db->selectCollection('config_drift_log');
        $this->ticketRefs = $db->selectCollection('ims_ticket_refs');
        $this->notifier = $notifier;
    }

    /**
     * @return array{scanned:int,drifted:int,skipped:int,tickets_created:int,unreachable_tickets:int,errors:array}
     */
    public function scanAll(): array
    {
        $summary = [
            'scanned' => 0,
            'drifted' => 0,
            'skipped' => 0,
            'tickets_created' => 0,
            'unreachable_tickets' => 0,
            'errors' => [],
        ];

        $cursor = $this->devices->find([
            'status' => ['$ne' => 'decommissioned'],
        ], ['limit' => 2000]);

        foreach ($cursor as $doc) {
            $device = json_decode(json_encode($doc), true);
            $deviceId = (string)($device['_id']['$oid'] ?? $device['_id'] ?? '');

            if ($deviceId === '') {
                continue;
            }

            if (!$this->isOnline($device)) {
                $summary['skipped']++;
                if ($this->shouldRaiseUnreachableTicket($device)) {
                    $ticketId = $this->createUnreachableTicket($device);
                    if ($ticketId !== null) {
                        $summary['unreachable_tickets']++;
                    }

                    $name = (string)($device['name'] ?? $deviceId);
                    $this->notify(
                        'device_unreachable',
                        4, // high
                        "Device unreachable: {$name}",
                        "Device {$name} has been unreachable for more than 15 minutes.",
                        [
                            'device_id' => $deviceId,
                            'ticket_id' => $ticketId ?? '',
                        ]
                    );
                }
                continue;
            }

            if (!$this->shouldScanNow($device)) {
                $summary['skipped']++;
                continue;
            }

            try {
                $summary['scanned']++;
                $drift = $this->detector->scanDevice($deviceId);

                if ($drift !== null) {
                    $summary['drifted']++;

                    $requiresApproval = (bool)($drift['requires_approval'] ?? false);
                    if ($requiresApproval) {
                        $ticketId = $this->createDriftTicket($device, $drift);
                        if ($ticketId !== null) {
                            $summary['tickets_created']++;
                            $this->driftLog->updateOne(
                                ['_id' => new ObjectId((string)$drift['drift_id'])],
                                ['$set' => ['ims_ticket_id' => $ticketId, 'updated_at' => new UTCDateTime()]]
                            );
                        }

                        // Fan out a notification alongside the ticket. Dispatch is
                        // best-effort and must never affect the scan outcome.
                        $name = (string)($device['name'] ?? $deviceId);
                        $this->notify(
                            'drift_detected',
                            3, // average
                            "Config drift detected on {$name}",
                            "Configuration drift was detected on device {$name} and requires operator review.",
                            [
                                'device_id' => $deviceId,
                                'drift_id'  => (string)($drift['drift_id'] ?? ''),
                                'ticket_id' => $ticketId ?? '',
                            ]
                        );
                    }
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = [
                    'device_id' => $deviceId,
                    'device_name' => (string)($device['name'] ?? ''),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    public function scanDevice(string $deviceId): array
    {
        $drift = $this->detector->scanDevice($deviceId);

        return [
            'device_id' => $deviceId,
            'drift_detected' => $drift !== null,
            'drift' => $drift,
        ];
    }

    private function shouldScanNow(array $device): bool
    {
        $role = (string)($device['role'] ?? 'access_switch');
        $interval = self::ROLE_INTERVALS_MINUTES[$role] ?? 30;

        $lastCheckedRaw = $device['drift']['last_checked'] ?? null;
        if ($lastCheckedRaw === null) {
            return true;
        }

        $lastChecked = $this->toUnixTime($lastCheckedRaw);
        if ($lastChecked === null) {
            return true;
        }

        return (time() - $lastChecked) >= ($interval * 60);
    }

    private function isOnline(array $device): bool
    {
        $status = (string)($device['status'] ?? 'unknown');
        return in_array($status, ['online', 'active'], true);
    }

    private function shouldRaiseUnreachableTicket(array $device): bool
    {
        $status = (string)($device['status'] ?? '');
        if (!in_array($status, ['unreachable', 'offline'], true)) {
            return false;
        }

        $deviceId = (string)($device['_id']['$oid'] ?? $device['_id'] ?? '');
        if ($deviceId === '') {
            return false;
        }

        $lastSeen = $this->toUnixTime($device['last_seen'] ?? null);
        if ($lastSeen === null) {
            return false;
        }

        if ((time() - $lastSeen) < (15 * 60)) {
            return false;
        }

        $existing = $this->ticketRefs->findOne([
            'device_id' => new ObjectId($deviceId),
            'type' => 'nms_device_unreachable',
            'status' => 'open',
        ]);

        return $existing === null;
    }

    private function createDriftTicket(array $device, array $drift): ?string
    {
        $client = $this->buildImsClient();
        if ($client === null) {
            return null;
        }

        $deviceId = (string)($device['_id']['$oid'] ?? $device['_id'] ?? '');
        $driftId = (string)($drift['drift_id'] ?? '');
        $name = (string)($device['name'] ?? $deviceId);

        try {
            $ticketId = $client->createTicket(
                'nms_drift',
                "Drift detected on {$name}",
                "Config drift detected for device {$name}. Drift ID: {$driftId}. Review and resolve via push/pull/ignore.",
                [
                    'device_id' => $deviceId,
                    'drift_id' => $driftId,
                    'source' => 'drift_scanner',
                ]
            );

            if ($ticketId !== '') {
                $this->ticketRefs->insertOne([
                    'device_id' => new ObjectId($deviceId),
                    'drift_id' => new ObjectId($driftId),
                    'type' => 'nms_drift',
                    'ims_ticket_id' => $ticketId,
                    'status' => 'open',
                    'created_at' => new UTCDateTime(),
                    'updated_at' => new UTCDateTime(),
                ]);
            }

            return $ticketId !== '' ? $ticketId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function createUnreachableTicket(array $device): ?string
    {
        $client = $this->buildImsClient();
        if ($client === null) {
            return null;
        }

        $deviceId = (string)($device['_id']['$oid'] ?? $device['_id'] ?? '');
        $name = (string)($device['name'] ?? $deviceId);

        try {
            $ticketId = $client->createTicket(
                'nms_device_unreachable',
                "Device unreachable: {$name}",
                "Device {$name} has been unreachable for more than 15 minutes.",
                [
                    'device_id' => $deviceId,
                    'source' => 'drift_scanner',
                ]
            );

            if ($ticketId !== '') {
                $this->ticketRefs->insertOne([
                    'device_id' => new ObjectId($deviceId),
                    'type' => 'nms_device_unreachable',
                    'ims_ticket_id' => $ticketId,
                    'status' => 'open',
                    'created_at' => new UTCDateTime(),
                    'updated_at' => new UTCDateTime(),
                ]);
            }

            return $ticketId !== '' ? $ticketId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Best-effort multi-channel notification. Lazily builds the
     * NotificationManager and swallows all failures — alert delivery must
     * never break or slow a drift scan.
     */
    private function notify(string $eventType, int $severity, string $title, string $body, array $sourceRef): void
    {
        try {
            if ($this->notifier === null) {
                $this->notifier = new NotificationManager();
            }
            $this->notifier->dispatch(
                new NotificationMessage($eventType, $severity, $title, $body, $sourceRef)
            );
        } catch (\Throwable) {
            // Intentionally ignored.
        }
    }

    private function buildImsClient(): ?ImsTicketClient
    {
        try {
            $config = require dirname(__DIR__, 2) . '/core/config/app.php';
            $vaultConfig = require dirname(__DIR__, 2) . '/core/config/vault.php';
            $secrets = !empty($vaultConfig['enabled'])
                ? new VaultSecretsManager($vaultConfig)
                : new AppEncryptedSecretsManager($vaultConfig);
            $jwt = new JWTHelper($secrets, $config);
            $m2m = new M2MTokenHelper($jwt, $config);

            return new ImsTicketClient($m2m, $config);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toUnixTime(mixed $value): ?int
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->getTimestamp();
        }

        if (is_array($value) && isset($value['$date']['$numberLong'])) {
            return (int)floor(((int)$value['$date']['$numberLong']) / 1000);
        }

        if (is_array($value) && isset($value['$date'])) {
            if (is_numeric($value['$date'])) {
                return (int)floor(((int)$value['$date']) / 1000);
            }
            $ts = strtotime((string)$value['$date']);
            return $ts === false ? null : $ts;
        }

        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts === false ? null : $ts;
        }

        return null;
    }
}
