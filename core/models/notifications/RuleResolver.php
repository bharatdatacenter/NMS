<?php

declare(strict_types=1);

namespace NMS\Core\Models\Notifications;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;

/**
 * RuleResolver
 *
 * CRUD over the `notification_rules` collection and the matching logic that
 * turns a NotificationMessage into a concrete set of delivery targets.
 *
 * Rule document:
 *   {
 *     name:         string,
 *     event_type:   string,   // exact event, or "*" to match any event
 *     min_severity: int,      // 0-5; rule fires when message.severity >= this
 *     enabled:      bool,
 *     targets:      [ { channel: "email"|"telegram", address: string } ],
 *     created_at, updated_at
 *   }
 */
class RuleResolver
{
    private \MongoDB\Collection $rules;

    public function __construct(?\MongoDB\Collection $rules = null)
    {
        $this->rules = $rules ?? MongoDB::getInstance()->selectCollection('notification_rules');
    }

    /**
     * Resolve all delivery targets for a message from enabled, matching rules.
     * Targets are de-duplicated across rules.
     *
     * @return list<array{channel:string,address:string,rule_id:string,rule_name:string}>
     */
    public function resolve(NotificationMessage $message): array
    {
        $cursor = $this->rules->find([
            'enabled'      => true,
            'event_type'   => ['$in' => [$message->eventType, '*']],
            'min_severity' => ['$lte' => $message->severity],
        ]);

        $resolved = [];
        $seen = [];

        foreach ($cursor as $doc) {
            $rule = json_decode(json_encode($doc), true);
            $ruleId = (string)($rule['_id']['$oid'] ?? $rule['_id'] ?? '');
            $ruleName = (string)($rule['name'] ?? $ruleId);

            foreach (($rule['targets'] ?? []) as $target) {
                $channel = strtolower(trim((string)($target['channel'] ?? '')));
                $address = trim((string)($target['address'] ?? ''));
                if ($channel === '' || $address === '') {
                    continue;
                }

                $dedupKey = $channel . '|' . $address;
                if (isset($seen[$dedupKey])) {
                    continue;
                }
                $seen[$dedupKey] = true;

                $resolved[] = [
                    'channel'   => $channel,
                    'address'   => $address,
                    'rule_id'   => $ruleId,
                    'rule_name' => $ruleName,
                ];
            }
        }

        return $resolved;
    }

    // ─── CRUD ──────────────────────────────────────────────────────────────────

    public function list(int $page = 1, int $perPage = 50): array
    {
        $total = $this->rules->countDocuments([]);
        $cursor = $this->rules->find([], [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['created_at' => -1],
        ]);

        return [
            'data'      => iterator_to_array($cursor, false),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / max(1, $perPage)),
        ];
    }

    public function create(array $data): string
    {
        $doc = $this->normalize($data);
        $doc['created_at'] = new UTCDateTime();
        $doc['updated_at'] = new UTCDateTime();

        $result = $this->rules->insertOne($doc);
        return (string)$result->getInsertedId();
    }

    public function update(string $id, array $data): bool
    {
        $set = $this->normalize($data, partial: true);
        if ($set === []) {
            return false;
        }
        $set['updated_at'] = new UTCDateTime();

        $result = $this->rules->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $set]
        );

        return $result->getMatchedCount() > 0;
    }

    public function delete(string $id): bool
    {
        $result = $this->rules->deleteOne(['_id' => new ObjectId($id)]);
        return $result->getDeletedCount() > 0;
    }

    /**
     * Validate + coerce a rule payload into its stored shape.
     *
     * @throws \InvalidArgumentException on invalid input
     */
    private function normalize(array $data, bool $partial = false): array
    {
        $out = [];

        if (!$partial || array_key_exists('name', $data)) {
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException('Rule name is required');
            }
            $out['name'] = $name;
        }

        if (!$partial || array_key_exists('event_type', $data)) {
            $eventType = trim((string)($data['event_type'] ?? ''));
            if ($eventType === '') {
                throw new \InvalidArgumentException('event_type is required (use "*" for all events)');
            }
            $out['event_type'] = $eventType;
        }

        if (!$partial || array_key_exists('min_severity', $data)) {
            $out['min_severity'] = NotificationMessage::clampSeverity((int)($data['min_severity'] ?? 0));
        }

        if (!$partial || array_key_exists('enabled', $data)) {
            $out['enabled'] = (bool)($data['enabled'] ?? true);
        }

        if (!$partial || array_key_exists('targets', $data)) {
            $out['targets'] = $this->normalizeTargets($data['targets'] ?? []);
        }

        return $out;
    }

    private function normalizeTargets(mixed $targets): array
    {
        if (!is_array($targets) || $targets === []) {
            throw new \InvalidArgumentException('At least one target is required');
        }

        $allowedChannels = ['email', 'telegram'];
        $normalized = [];

        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $channel = strtolower(trim((string)($target['channel'] ?? '')));
            $address = trim((string)($target['address'] ?? ''));

            if (!in_array($channel, $allowedChannels, true)) {
                throw new \InvalidArgumentException(
                    "Invalid channel '{$channel}'. Allowed: " . implode(', ', $allowedChannels)
                );
            }
            if ($address === '') {
                throw new \InvalidArgumentException("Target address is required for channel '{$channel}'");
            }
            if ($channel === 'email' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException("Invalid email address: {$address}");
            }

            $normalized[] = ['channel' => $channel, 'address' => $address];
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one valid target is required');
        }

        return $normalized;
    }
}
