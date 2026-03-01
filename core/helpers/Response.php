<?php

declare(strict_types=1);

namespace NMS\Core\Helpers;

/**
 * Standardized JSON response helper.
 */
class Response
{
    /**
     * Send a JSON response and exit.
     */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send a JSON error response and exit.
     */
    public static function error(string $message, int $status = 400, ?array $details = null): never
    {
        $body = ['error' => $message];
        if ($details !== null) {
            $body['details'] = $details;
        }
        self::json($body, $status);
    }

    /**
     * Send a paginated JSON response and exit.
     */
    public static function paginated(array $data, int $total, int $page, int $perPage = 50): never
    {
        self::json([
            'data'      => $data,
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int)ceil($total / max(1, $perPage)),
            ],
        ]);
    }

    public static function success(array $data = [], string $message = 'Success'): never
    {
        self::json(array_merge(['message' => $message], $data), 200);
    }

    public static function created(array $data = []): never
    {
        self::json($data, 201);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    public static function notFound(string $message = 'Not found'): never
    {
        self::error($message, 404);
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403);
    }

    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::error($message, 401);
    }

    public static function unprocessable(string $message, ?array $details = null): never
    {
        self::error($message, 422, $details);
    }

    public static function tooManyRequests(string $message = 'Too many requests'): never
    {
        self::error($message, 429);
    }

    public static function conflict(string $message): never
    {
        self::error($message, 409);
    }
}
