<?php

declare(strict_types=1);

/**
 * POST /api/integration/ims/validate-availability
 *
 * Read-only check: can we provision a server with the given requirements?
 * Validates pool availability, device reachability, BGP conflicts, cluster health.
 * Returns immediately — no mutations.
 *
 * Body: {
 *   datacenter?, ipv4_l2?, ipv4_l3?, ipv6_l2?, ipv6_l3?,
 *   device_id?, cluster_id?, mac_address?,
 *   pool_id_ipv4_l2?, pool_id_ipv4_l3?, pool_id_ipv6_l2?, pool_id_ipv6_l3?,
 *   address_families?
 * }
 */

use NMS\Core\Models\Provisioning\ProvisioningEngine;
use NMS\Core\Helpers\Response;

try {
    // Build a request-like array for validatePhase0
    $validationRequest = array_merge($body, [
        'address_families' => $body['address_families'] ?? array_filter([
            !empty($body['ipv4_l2']) || !empty($body['ipv4_l3']) ? 'ipv4' : null,
            !empty($body['ipv6_l2']) || !empty($body['ipv6_l3']) ? 'ipv6' : null,
        ]),
        'l2_ip_count' => (int)($body['ipv4_l2'] ?? $body['l2_ip_count'] ?? 1),
        'l3_ip_count' => (int)($body['ipv4_l3'] ?? $body['l3_ip_count'] ?? 1),
    ]);

    $engine = new ProvisioningEngine();
    $errors = $engine->validatePhase0($validationRequest);

    $available = empty($errors);

    Response::json([
        'available' => $available,
        'checks'    => array_map(fn($v) => ['status' => 'failed', 'message' => $v], $errors)
                     + ($available ? ['all' => ['status' => 'passed']] : []),
        'errors'    => $errors,
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Availability check failed: ' . $e->getMessage(), 500);
}
