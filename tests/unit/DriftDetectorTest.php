<?php

declare(strict_types=1);

namespace Tests\Unit;

use NMS\Core\Models\Drift\DriftDetector;
use PHPUnit\Framework\TestCase;

class DriftDetectorTest extends TestCase
{
    public function testCompareSectionDetectsAddedRemovedAndModifiedEntries(): void
    {
        $ref = new \ReflectionClass(DriftDetector::class);
        /** @var DriftDetector $detector */
        $detector = $ref->newInstanceWithoutConstructor();

        $deviceState = [
            ['rule_id' => '1', 'action' => 'allow', 'name' => 'Rule-A'],
            ['rule_id' => '2', 'action' => 'deny', 'name' => 'Rule-B'],
        ];

        $nmsState = [
            ['rule_id' => '2', 'action' => 'allow', 'name' => 'Rule-B'],
            ['rule_id' => '3', 'action' => 'allow', 'name' => 'Rule-C'],
        ];

        $diffs = $detector->compareSection('firewall', $deviceState, $nmsState);

        $this->assertCount(3, $diffs);

        $actionsById = [];
        foreach ($diffs as $diff) {
            $actionsById[$diff['identifier']] = $diff['action'];
        }

        $this->assertSame('added_on_device', $actionsById['firewall:1'] ?? null);
        $this->assertSame('modified', $actionsById['firewall:2'] ?? null);
        $this->assertSame('missing_on_device', $actionsById['firewall:3'] ?? null);
    }
}
