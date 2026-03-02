<?php

declare(strict_types=1);

/**
 * PUT /api/provision/manual-queue/{id}/resolve
 *
 * Mark a manual intervention queue item as resolved.
 * Body: { resolution_note?, close_ims_ticket? }
 */

use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Auth\ImsTicketClient;
use NMS\Core\Auth\M2MTokenHelper;
use NMS\Core\Auth\JWTHelper;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Helpers\Response;

try {
    $itemId = (string)($params['id'] ?? '');
    if ($itemId === '') {
        Response::notFound('Queue item ID is required');
    }

    $db         = MongoDB::getInstance();
    $collection = $db->selectCollection('manual_intervention_queue');
    $doc        = $collection->findOne(['_id' => new ObjectId($itemId)]);

    if ($doc === null) {
        Response::notFound("Queue item {$itemId} not found");
    }

    $arr = json_decode(json_encode($doc), true);
    if (($arr['status'] ?? '') === 'resolved') {
        Response::json(['success' => false, 'message' => 'Item is already resolved'], 409);
    }

    $resolutionNote = (string)($body['resolution_note'] ?? '');
    $resolvedBy     = $user['id'] ?? null;

    $collection->updateOne(
        ['_id' => new ObjectId($itemId)],
        ['$set' => [
            'status'      => 'resolved',
            'resolved_at' => new UTCDateTime(),
            'resolved_by' => $resolvedBy ? new ObjectId($resolvedBy) : null,
            'resolution_note' => $resolutionNote,
        ]]
    );

    // Optionally close the IMS ticket
    $imsTicketId = (string)($arr['ims_ticket_id'] ?? '');
    if ($imsTicketId !== '' && !empty($body['close_ims_ticket'])) {
        try {
            $config    = require dirname(__DIR__, 4) . '/core/config/app.php';
            $secrets   = new VaultSecretsManager();
            $jwtHelper = new JWTHelper($secrets);
            $m2mHelper = new M2MTokenHelper($jwtHelper, $config);
            $client    = new ImsTicketClient($m2mHelper, $config);
            $client->updateTicket($imsTicketId, [
                'status'  => 'resolved',
                'comment' => $resolutionNote ?: 'Resolved via NMS manual queue',
            ]);
        } catch (\Throwable) {
            // Non-critical: IMS ticket update failure should not fail this request
        }
    }

    Response::json(['success' => true, 'message' => 'Queue item resolved']);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::notFound($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to resolve queue item: ' . $e->getMessage(), 500);
}
