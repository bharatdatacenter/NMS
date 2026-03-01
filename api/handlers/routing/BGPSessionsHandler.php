<?php

declare(strict_types=1);

/**
 * GET /api/routing/bgp/sessions
 *
 * List all BGP sessions stored in bgp_sessions collection.
 * Query params: device_id, state, page, per_page
 */

use NMS\Core\Models\Routing\BGPMonitor;
use NMS\Core\Helpers\Response;

try {
    $monitor = new BGPMonitor();
    $result  = $monitor->listSessions(
        [
            'device_id' => $query['device_id'] ?? null,
            'state'     => $query['state']     ?? null,
        ],
        (int) ($query['page']     ?? 1),
        (int) ($query['per_page'] ?? 100)
    );

    Response::json($result);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list BGP sessions: ' . $e->getMessage(), 500);
}
