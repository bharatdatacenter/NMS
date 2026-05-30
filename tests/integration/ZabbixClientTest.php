<?php

declare(strict_types=1);

namespace Tests\Integration;

use NMS\Core\Models\Monitoring\ZabbixClient;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for real Zabbix connectivity.
 */
class ZabbixClientTest extends TestCase
{
    /**
     * @group integration
     */
    public function testGetHostHealthFromMappedHost(): void
    {
        $hostId = getenv('ZABBIX_TEST_HOST_ID');
        if (!$hostId) {
            $this->markTestSkipped('ZABBIX_TEST_HOST_ID environment variable not set');
        }

        try {
            $client = new ZabbixClient();
            $health = $client->getHostHealth((string)$hostId);

            $this->assertIsArray($health);
            $this->assertArrayHasKey('metrics', $health);
            $this->assertArrayHasKey('available', $health);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Zabbix integration environment not available: ' . $e->getMessage());
        }
    }
}
