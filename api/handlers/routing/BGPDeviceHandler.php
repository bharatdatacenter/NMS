<?php

declare(strict_types=1);

/**
 * GET /api/routing/bgp/sessions/{device_id}
 *
 * Poll and return live BGP sessions for a specific device.
 * Polls the device, upserts into bgp_sessions, then returns stored sessions.
 */

use NMS\Core\Models\Routing\BGPMonitor;
use NMS\Core\Helpers\Response;

try {
    $deviceId = $params['device_id'] ?? '';
    $monitor  = new BGPMonitor();

    // Poll live — upserts into bgp_sessions collection
    $monitor->pollSessions($deviceId);

    // Return stored sessions for this device
    $sessions = $monitor->getDeviceSessions($deviceId);

    Response::json(['data' => $sessions]);

} catch (Response) {
    // Already sent
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (\Exception $e) {
    Response::error('BGP poll failed: ' . $e->getMessage(), 500);
}
