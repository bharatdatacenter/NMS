<?php

declare(strict_types=1);

/**
 * POST /api/firewall/policies/{id}/sync
 *
 * Push a firewall policy to its device via the vendor adapter.
 * If the device is in an HA cluster, pushes to cluster management_ip.
 */

use NMS\Core\Models\Firewall\PolicyManager;
use NMS\Core\Helpers\Response;

try {
    $id      = $params['id'] ?? '';
    $manager = new PolicyManager();
    $policy  = $manager->findById($id);

    if (!$policy) {
        Response::notFound('Policy not found');
    }

    $ok = $manager->syncToDevice($id);

    Response::json(['data' => ['synced' => $ok]]);

} catch (Response) {
    // Already sent
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (\Exception $e) {
    Response::error('Sync failed: ' . $e->getMessage(), 500);
}
