<?php

declare(strict_types=1);

/**
 * POST /api/ipam/blocks
 *
 * Create a new IP block (top-level allocation from a RIR).
 *
 * Required: network (CIDR)
 * Optional: ip_version, source, rir_handle, description, status
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'network' => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    // Validate CIDR format
    try {
        $parsed  = \NMS\Core\Helpers\IPUtils::parseCIDR($body['network']);
    } catch (\InvalidArgumentException $e) {
        Response::unprocessable('Invalid CIDR', ['network' => $e->getMessage()]);
    }

    // Validate ip_version if provided
    if (isset($body['ip_version']) && !in_array($body['ip_version'], ['ipv4', 'ipv6'], true)) {
        Response::unprocessable('Invalid ip_version', ['ip_version' => 'Must be ipv4 or ipv6']);
    }

    $manager = new PoolManager();
    $id      = $manager->createBlock($body);
    $block   = $manager->findBlockById($id);

    Response::json(['data' => $block], 201);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to create IP block: ' . $e->getMessage(), 500);
}
