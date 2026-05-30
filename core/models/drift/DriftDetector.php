<?php

declare(strict_types=1);

namespace NMS\Core\Models\Drift;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Core\Models\Secrets\VaultSecretsManager;

/**
 * DriftDetector
 *
 * Performs section-by-section drift detection and resolution.
 */
class DriftDetector
{
    private \MongoDB\Collection $devices;
    private \MongoDB\Collection $driftLog;
    private \MongoDB\Collection $expectedState;
    private \MongoDB\Collection $firewallPolicies;

    private DeviceManager $deviceManager;
    private SecretsManagerInterface $secrets;

    public function __construct(?SecretsManagerInterface $secrets = null)
    {
        $db = MongoDB::getInstance();
        $this->devices = $db->selectCollection('devices');
        $this->driftLog = $db->selectCollection('config_drift_log');
        $this->expectedState = $db->selectCollection('device_expected_state');
        $this->firewallPolicies = $db->selectCollection('firewall_policies');

        $this->deviceManager = new DeviceManager();
        $this->secrets = $secrets ?? $this->buildSecretsManager();
    }

    /**
     * Scan a single device and persist drift entries if differences exist.
     */
    public function scanDevice(string $deviceId): ?array
    {
        $device = $this->deviceManager->findById($deviceId);
        if ($device === null) {
            throw new \RuntimeException("Device {$deviceId} not found");
        }

        $adapter = DeviceFactory::create($device, $this->secrets);
        if ($adapter === null) {
            throw new \RuntimeException("No adapter for vendor '{$device['vendor']}'");
        }

        $adapter->connect();
        try {
            $deviceState = (array)$adapter->getConfigSections();
        } finally {
            $adapter->disconnect();
        }

        $nmsState = $this->getExpectedState($deviceId);

        $sections = array_values(array_unique(array_merge(array_keys($deviceState), array_keys($nmsState))));
        $diffs = [];

        foreach ($sections as $section) {
            $deviceSection = (array)($deviceState[$section] ?? []);
            $nmsSection = (array)($nmsState[$section] ?? []);

            $sectionDiffs = $this->compareSection($section, $deviceSection, $nmsSection);
            if (!empty($sectionDiffs)) {
                $diffs = array_merge($diffs, $sectionDiffs);
            }
        }

        $now = new UTCDateTime();
        $deviceOid = new ObjectId($deviceId);

        $this->devices->updateOne(
            ['_id' => $deviceOid],
            ['$set' => [
                'drift.last_checked' => $now,
                'drift.status' => empty($diffs) ? 'clean' : 'drifted',
                'drift.open_drift_count' => empty($diffs)
                    ? 0
                    : $this->countOpenDrifts($deviceId) + 1,
                'drift.last_drifted' => empty($diffs) ? ($device['drift']['last_drifted'] ?? null) : $now,
            ]]
        );

        if (empty($diffs)) {
            return null;
        }

        $driftDoc = [
            'device_id' => $deviceOid,
            'device_name' => (string)($device['name'] ?? ''),
            'status' => 'open',
            'requires_approval' => (bool)($device['drift']['requires_approval'] ?? false),
            'open_drift_count' => $this->countOpenDrifts($deviceId) + 1,
            'diffs' => $diffs,
            'device_snapshot' => $deviceState,
            'nms_snapshot' => $nmsState,
            'resolution' => null,
            'ims_ticket_id' => null,
            'detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $insert = $this->driftLog->insertOne($driftDoc);

        return [
            'drift_id' => (string)$insert->getInsertedId(),
            'device_id' => $deviceId,
            'diff_count' => count($diffs),
            'status' => 'open',
            'requires_approval' => $driftDoc['requires_approval'],
        ];
    }

    /**
     * Compare section records and return normalized drift operations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compareSection(string $section, array $deviceState, array $nmsState): array
    {
        $deviceMap = [];
        foreach ($deviceState as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = $this->identifierForEntry($section, $entry);
            $deviceMap[$id] = $this->normalizeValue($entry);
        }

        $nmsMap = [];
        foreach ($nmsState as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = $this->identifierForEntry($section, $entry);
            $nmsMap[$id] = $this->normalizeValue($entry);
        }

        $allIds = array_values(array_unique(array_merge(array_keys($deviceMap), array_keys($nmsMap))));
        $diffs = [];

        foreach ($allIds as $id) {
            $hasDevice = array_key_exists($id, $deviceMap);
            $hasNms = array_key_exists($id, $nmsMap);

            if ($hasDevice && !$hasNms) {
                $diffs[] = [
                    'section' => $section,
                    'action' => 'added_on_device',
                    'identifier' => $id,
                    'device_value' => $deviceMap[$id],
                    'nms_value' => null,
                ];
                continue;
            }

            if (!$hasDevice && $hasNms) {
                $diffs[] = [
                    'section' => $section,
                    'action' => 'missing_on_device',
                    'identifier' => $id,
                    'device_value' => null,
                    'nms_value' => $nmsMap[$id],
                ];
                continue;
            }

            if ($deviceMap[$id] !== $nmsMap[$id]) {
                $diffs[] = [
                    'section' => $section,
                    'action' => 'modified',
                    'identifier' => $id,
                    'device_value' => $deviceMap[$id],
                    'nms_value' => $nmsMap[$id],
                ];
            }
        }

        return $diffs;
    }

    public function resolveAsPush(string $driftId): void
    {
        $drift = $this->getOpenDriftById($driftId);
        $deviceId = $this->extractObjectIdString($drift['device_id'] ?? null);

        if ($deviceId === '') {
            throw new \RuntimeException('Drift document is missing device_id');
        }

        $device = $this->deviceManager->findById($deviceId);
        if ($device === null) {
            throw new \RuntimeException("Device {$deviceId} not found");
        }

        $adapter = DeviceFactory::create($device, $this->secrets);
        if ($adapter === null) {
            throw new \RuntimeException("No adapter for vendor '{$device['vendor']}'");
        }

        $adapter->connect();
        try {
            foreach ((array)($drift['diffs'] ?? []) as $diff) {
                if (!is_array($diff)) {
                    continue;
                }
                $this->applyPushDiff($adapter, $diff);
            }
        } finally {
            $adapter->disconnect();
        }

        $this->closeDrift($driftId, 'push');
        $this->refreshDeviceDriftState($deviceId);
    }

    public function resolveAsPull(string $driftId): void
    {
        $drift = $this->getOpenDriftById($driftId);
        $deviceId = $this->extractObjectIdString($drift['device_id'] ?? null);

        if ($deviceId === '') {
            throw new \RuntimeException('Drift document is missing device_id');
        }

        $deviceState = (array)($drift['device_snapshot'] ?? []);

        $this->expectedState->updateOne(
            ['device_id' => new ObjectId($deviceId)],
            ['$set' => [
                'device_id' => new ObjectId($deviceId),
                'sections' => $deviceState,
                'last_updated_at' => new UTCDateTime(),
                'source' => 'pull_resolution',
            ]],
            ['upsert' => true]
        );

        $this->closeDrift($driftId, 'pull');
        $this->refreshDeviceDriftState($deviceId);
    }

    public function resolveAsIgnore(string $driftId): void
    {
        $drift = $this->getOpenDriftById($driftId);
        $deviceId = $this->extractObjectIdString($drift['device_id'] ?? null);

        $this->closeDrift($driftId, 'ignore');
        if ($deviceId !== '') {
            $this->refreshDeviceDriftState($deviceId);
        }
    }

    private function applyPushDiff(object $adapter, array $diff): void
    {
        $section = (string)($diff['section'] ?? '');
        $action = (string)($diff['action'] ?? '');
        $deviceValue = is_array($diff['device_value'] ?? null) ? $diff['device_value'] : [];
        $nmsValue = is_array($diff['nms_value'] ?? null) ? $diff['nms_value'] : [];

        if ($section === 'firewall') {
            if ($action === 'added_on_device' || $action === 'modified') {
                $ruleId = (string)($deviceValue['rule_id'] ?? $deviceValue['policy_id'] ?? '');
                if ($ruleId !== '') {
                    $adapter->removeFirewallRule($ruleId);
                }
            }
            if ($action === 'missing_on_device' || $action === 'modified') {
                if (!empty($nmsValue)) {
                    $adapter->addFirewallRule($nmsValue);
                }
            }
            return;
        }
    }

    private function getExpectedState(string $deviceId): array
    {
        $expected = $this->expectedState->findOne(['device_id' => new ObjectId($deviceId)]);
        if ($expected !== null && isset($expected['sections'])) {
            return $this->normalizeValue($expected['sections']);
        }

        $derived = [
            'firewall' => $this->collectExpectedFirewall($deviceId),
            'interfaces' => $this->collectExpectedInterfaces($deviceId),
        ];

        $this->expectedState->updateOne(
            ['device_id' => new ObjectId($deviceId)],
            ['$set' => [
                'device_id' => new ObjectId($deviceId),
                'sections' => $derived,
                'last_updated_at' => new UTCDateTime(),
                'source' => 'derived_from_collections',
            ]],
            ['upsert' => true]
        );

        return $derived;
    }

    private function collectExpectedFirewall(string $deviceId): array
    {
        $cursor = $this->firewallPolicies->find([
            'device_id' => new ObjectId($deviceId),
            'enabled' => ['$ne' => false],
        ]);

        $out = [];
        foreach ($cursor as $doc) {
            $row = $this->normalizeValue((array)$doc);
            $out[] = [
                'policy_id' => $row['policy_id'] ?? ($row['id'] ?? null),
                'rule_id' => $row['rule_id'] ?? null,
                'name' => $row['name'] ?? null,
                'action' => $row['action'] ?? null,
                'direction' => $row['direction'] ?? null,
                'source_zone' => $row['source_zone'] ?? null,
                'destination_zone' => $row['destination_zone'] ?? null,
                'ip_version' => $row['ip_version'] ?? null,
                'sequence' => $row['sequence'] ?? null,
            ];
        }

        return $out;
    }

    private function collectExpectedInterfaces(string $deviceId): array
    {
        $device = $this->deviceManager->findById($deviceId);
        $ports = (array)($device['ports'] ?? []);

        $out = [];
        foreach ($ports as $port) {
            if (!is_array($port)) {
                continue;
            }
            $out[] = [
                'name' => $port['name'] ?? null,
                'status' => $port['status'] ?? null,
                'type' => $port['type'] ?? null,
                'ip' => $port['ip'] ?? null,
                'mac_address' => $port['mac_address'] ?? null,
            ];
        }

        return $out;
    }

    private function identifierForEntry(string $section, array $entry): string
    {
        if ($section === 'firewall') {
            foreach (['rule_id', 'policy_id', 'id', 'name', 'rule_number'] as $key) {
                $value = (string)($entry[$key] ?? '');
                if ($value !== '') {
                    return "{$section}:{$value}";
                }
            }
        }

        if ($section === 'interfaces') {
            $name = (string)($entry['name'] ?? $entry['interface'] ?? '');
            if ($name !== '') {
                return "{$section}:{$name}";
            }
        }

        return $section . ':' . hash('sha256', json_encode($this->normalizeValue($entry)) ?: '');
    }

    private function getOpenDriftById(string $driftId): array
    {
        $doc = $this->driftLog->findOne([
            '_id' => new ObjectId($driftId),
            'status' => 'open',
        ]);

        if ($doc === null) {
            throw new \RuntimeException("Open drift {$driftId} not found");
        }

        return $this->normalizeValue((array)$doc);
    }

    private function closeDrift(string $driftId, string $action): void
    {
        $this->driftLog->updateOne(
            ['_id' => new ObjectId($driftId)],
            ['$set' => [
                'status' => 'resolved',
                'resolution' => [
                    'action' => $action,
                    'resolved_at' => new UTCDateTime(),
                ],
                'updated_at' => new UTCDateTime(),
            ]]
        );
    }

    private function refreshDeviceDriftState(string $deviceId): void
    {
        $open = $this->countOpenDrifts($deviceId);
        $this->devices->updateOne(
            ['_id' => new ObjectId($deviceId)],
            ['$set' => [
                'drift.open_drift_count' => $open,
                'drift.status' => $open > 0 ? 'drifted' : 'clean',
                'drift.last_checked' => new UTCDateTime(),
            ]]
        );
    }

    private function countOpenDrifts(string $deviceId): int
    {
        return $this->driftLog->countDocuments([
            'device_id' => new ObjectId($deviceId),
            'status' => 'open',
        ]);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string)$value;
        }

        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return $this->normalizeValue((array)$value);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[$k] = $this->normalizeValue($v);
            }

            if ($this->isAssoc($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        return $value;
    }

    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function extractObjectIdString(mixed $value): string
    {
        if ($value instanceof ObjectId) {
            return (string)$value;
        }

        if (is_array($value)) {
            if (isset($value['$oid'])) {
                return (string)$value['$oid'];
            }
            return '';
        }

        return is_string($value) ? $value : '';
    }

    private function buildSecretsManager(): SecretsManagerInterface
    {
        $vaultConfig = require dirname(__DIR__, 3) . '/core/config/vault.php';
        if (!empty($vaultConfig['enabled'])) {
            return new VaultSecretsManager($vaultConfig);
        }
        return new AppEncryptedSecretsManager($vaultConfig);
    }
}
