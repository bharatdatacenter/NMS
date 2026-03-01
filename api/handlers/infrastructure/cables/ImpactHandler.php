<?php

declare(strict_types=1);

/**
 * GET /api/cables/impact/{cable_id}
 *
 * Impact analysis: which connectivity_paths use this cable?
 * Used to assess blast radius before removing or modifying a cable.
 */

use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Models\Topology\PathMaterializer;
use NMS\Core\Helpers\Response;

try {
    $cableId = $params['cable_id'] ?? '';
    if (empty($cableId)) {
        Response::error('Cable ID required', 400);
    }

    // Validate cable exists
    $cableManager = new CableManager();
    $cable = $cableManager->findById($cableId);
    if (!$cable) {
        Response::error('Cable not found', 404);
    }

    // Use cable_id label (physical label), not MongoDB _id
    $cableLabelId = $cable['cable_id'] ?? $cableId;

    $pm      = new PathMaterializer();
    $paths   = $pm->getImpactedPaths($cableLabelId);

    Response::json([
        'cable_id'       => $cableId,
        'cable_label'    => $cableLabelId,
        'impacted_paths' => count($paths),
        'data'           => $paths,
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid cable ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to compute impact: ' . $e->getMessage(), 500);
}
