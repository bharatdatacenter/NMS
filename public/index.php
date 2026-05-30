<?php

declare(strict_types=1);

/**
 * NMS Web Entry Point
 *
 * Routes to API (JSON) or frontend views based on Accept header / path.
 */

define('NMS_ROOT', dirname(__DIR__));
define('NMS_START', microtime(true));

require_once NMS_ROOT . '/vendor/autoload.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// All /api/* requests go to API router
if (str_starts_with($uri, '/api/')) {
    require NMS_ROOT . '/api/api.php';
    exit;
}

// Webhooks also go through API router
if (str_starts_with($uri, '/api/webhooks/')) {
    require NMS_ROOT . '/api/api.php';
    exit;
}

// Frontend views
$acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
if (str_contains($acceptHeader, 'text/html') || str_starts_with($uri, '/views/')) {
    // Authenticate session
    session_start();
    $jwtToken = $_COOKIE['nms_token'] ?? $_SESSION['access_token'] ?? null;

    $publicRoutes = ['/', '/login', '/auth/login'];
    if (!$jwtToken && !in_array($uri, $publicRoutes)) {
        header('Location: /login');
        exit;
    }

    // Route to view
    $viewMap = [
        '/'           => 'dashboard/index',
        '/login'      => 'auth/login',
        '/devices'    => 'devices/index',
        '/topology'   => 'topology/logical',
        '/ipam'       => 'ipam/index',

        '/drift'      => 'drift/index',
        '/audit'      => 'audit/index',
        '/settings'   => 'settings/index',
    ];

    $viewFile = $viewMap[$uri] ?? null;
    if ($viewFile) {
        $viewPath = NMS_ROOT . '/views/' . $viewFile . '.php';
        if (file_exists($viewPath)) {
            require $viewPath;
            exit;
        }
    }

    http_response_code(404);
    echo '<h1>404 Not Found</h1>';
    exit;
}

// Default: JSON 404
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found']);
