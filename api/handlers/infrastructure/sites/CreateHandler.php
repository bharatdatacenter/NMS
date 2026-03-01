<?php

declare(strict_types=1);

/**
 * POST /api/sites
 *
 * Create a new site (data center, colocation, PoP, or office).
 *
 * Required: name, code
 * Optional: type, address, provider, contract_id, uplinks, contacts, notes, status
 */

use NMS\Core\Models\Infrastructure\SiteManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'name' => 'required|string',
        'code' => 'required|string',
    ]);

    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $validTypes = ['datacenter', 'colocation', 'pop', 'office'];
    if (isset($body['type']) && !in_array($body['type'], $validTypes, true)) {
        Response::unprocessable('Invalid type', ['type' => 'Must be one of: ' . implode(', ', $validTypes)]);
    }

    $manager = new SiteManager();

    // Ensure code is unique
    $existing = $manager->findOne(['code' => strtoupper($body['code'])]);
    if ($existing) {
        Response::unprocessable('Duplicate code', ['code' => 'Site code already exists']);
    }

    $id   = $manager->create($body);
    $site = $manager->findById($id);

    Response::json(['data' => $site], 201);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to create site: ' . $e->getMessage(), 500);
}
