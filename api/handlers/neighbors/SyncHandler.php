<?php

declare(strict_types=1);

/**
 * POST /api/neighbors/sync
 *
 * Push a neighbor entry to its device via the vendor adapter.
 * Required body: entry_id
 */

use NMS\Core\Models\Neighbors\NeighborSync;
use NMS\Core\Models\Neighbors\NeighborManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'entry_id' => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $entryId = $body['entry_id'];
    $manager = new NeighborManager();
    $entry   = $manager->findById($entryId);

    if (!$entry) {
        Response::notFound('Neighbor entry not found');
    }

    $sync = new NeighborSync();
    $ok   = $sync->syncToDevice($entryId);

    Response::json(['data' => ['synced' => $ok]]);

} catch (Response) {
    // Already sent
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (\Exception $e) {
    Response::error('Sync failed: ' . $e->getMessage(), 500);
}
