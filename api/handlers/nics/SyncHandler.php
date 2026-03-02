<?php

declare(strict_types=1);

/**
 * POST /api/nics/sync/{ims_server_id}
 *
 * Sync NIC data for a server from IMS via M2M API call.
 * Fetches current NIC list from IMS and upserts into server_nics collection.
 */

use NMS\Core\Models\Nics\NicManager;
use NMS\Core\Auth\ImsTicketClient;
use NMS\Core\Auth\M2MTokenHelper;
use NMS\Core\Auth\JWTHelper;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Helpers\Response;

try {
    $serverId = (string)($params['ims_server_id'] ?? '');
    if ($serverId === '') {
        Response::notFound('IMS server ID is required');
    }

    $config    = require dirname(__DIR__, 4) . '/core/config/app.php';
    $imsUrl    = rtrim((string)($config['ims']['api_url'] ?? ''), '/');

    if ($imsUrl === '') {
        Response::error('IMS API URL not configured', 503);
    }

    // Fetch NIC list from IMS via M2M token
    $secrets   = new VaultSecretsManager();
    $jwtHelper = new JWTHelper($secrets);
    $m2mHelper = new M2MTokenHelper($jwtHelper, $config);
    $token     = $m2mHelper->issueForIms();

    $ch = curl_init("{$imsUrl}/api/server/{$serverId}/hardware/nic");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);
    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError    = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false || $curlError !== '') {
        Response::error('Failed to fetch NICs from IMS: ' . $curlError, 502);
    }

    $imsResponse = json_decode((string)$responseBody, true);

    if ($httpCode >= 400) {
        Response::error(
            'IMS returned HTTP ' . $httpCode . ': ' . ($imsResponse['message'] ?? 'error'),
            502
        );
    }

    $nics = (array)($imsResponse['data'] ?? $imsResponse['nics'] ?? []);

    if (empty($nics)) {
        Response::json(['success' => true, 'synced' => 0, 'message' => 'No NICs found for this server']);
    }

    $manager = new NicManager();
    $manager->syncFromWebhook($serverId, $nics);

    Response::json([
        'success' => true,
        'synced'  => count($nics),
        'message' => 'NIC sync completed',
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('NIC sync failed: ' . $e->getMessage(), 500);
}
