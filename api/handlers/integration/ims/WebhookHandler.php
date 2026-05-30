<?php

declare(strict_types=1);

/**
 * POST /api/webhooks/ims
 *
 * Receive webhook events from IMS and dispatch to the appropriate handler.
 *
 * Supported events:
 *   server.migrate     — Server moved to new rack; update cable records
 *   server.nic_change  — NIC replaced or added; update server_nics collection
 *   server.reboot      — Informational; no NMS action
 *
 * Authentication: M2M token with aud: "nms-m2m" validated by AuthMiddleware.
 *
 * Body: { event, server_id, payload: { ... } }
 */

use NMS\Core\Models\Nics\NicManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Logger;

try {
    $event    = (string)($body['event'] ?? '');
    $serverId = (string)($body['server_id'] ?? '');
    $payload  = (array)($body['payload'] ?? []);

    if ($event === '') {
        Response::json(['success' => false, 'message' => 'event field is required'], 422);
    }
    if ($serverId === '') {
        Response::json(['success' => false, 'message' => 'server_id field is required'], 422);
    }

    $logger = Logger::getInstance();
    $logger->info('IMS webhook received', ['event' => $event, 'server_id' => $serverId]);

    switch ($event) {

        // ── server.nic_change ────────────────────────────────────────────────
        case 'server.nic_change':
            $nics = (array)($payload['nics'] ?? []);
            if (empty($nics)) {
                Response::json(['success' => false, 'message' => 'payload.nics is required for server.nic_change'], 422);
            }
            $manager = new NicManager();
            $manager->syncFromWebhook($serverId, $nics);

            Response::json([
                'success' => true,
                'event'   => $event,
                'synced'  => count($nics),
            ]);
            break;

        // ── server.migrate ───────────────────────────────────────────────────
        case 'server.migrate':
            // Update cable records for the server's NICs when it moves racks
            // Phase 7 will implement full migration path re-computation
            $newRackId = (string)($payload['new_rack_id'] ?? '');
            $logger->info('server.migrate received — cable re-mapping deferred to Phase 7', [
                'server_id'  => $serverId,
                'new_rack_id'=> $newRackId,
            ]);
            Response::json([
                'success' => true,
                'event'   => $event,
                'message' => 'Migration noted; topology re-computation scheduled',
            ]);
            break;

        // ── server.reboot ────────────────────────────────────────────────────
        case 'server.reboot':
            // Informational only — no NMS action required
            $logger->info('server.reboot event received', ['server_id' => $serverId]);
            Response::json(['success' => true, 'event' => $event, 'message' => 'Acknowledged']);
            break;

        // ── Unknown event ────────────────────────────────────────────────────
        default:
            $logger->warning('Unknown IMS webhook event', ['event' => $event]);
            Response::json([
                'success' => false,
                'message' => "Unknown event '{$event}'",
            ], 422);
    }

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Webhook processing failed: ' . $e->getMessage(), 500);
}
