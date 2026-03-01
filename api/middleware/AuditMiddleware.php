<?php

declare(strict_types=1);

namespace NMS\Api\Middleware;

use NMS\Core\Database\MongoDB;
use MongoDB\BSON\UTCDateTime;
use stdClass;

/**
 * AuditMiddleware — logs every mutating request to audit_logs collection.
 * Called after handler execution for POST/PUT/PATCH/DELETE.
 */
class AuditMiddleware
{
    public static function log(array $request, stdClass $claims, string $handler): void
    {
        try {
            $db = MongoDB::getInstance();
            $collection = $db->selectCollection('audit_logs');

            // Parse resource type and ID from handler path
            $parts        = explode('/', $handler);
            $resourceType = $parts[count($parts) - 2] ?? 'unknown';
            $resourceId   = $request['params']['id'] ?? null;

            // TTL: 90 days from now
            $expiresAt = new UTCDateTime((time() + 90 * 24 * 3600) * 1000);

            $collection->insertOne([
                'timestamp'        => new UTCDateTime(),
                'user_id'          => $claims->sub ?? null,
                'user_roles'       => $claims->roles ?? [],
                'method'           => $request['method'],
                'uri'              => $request['uri'],
                'handler'          => $handler,
                'resource_type'    => $resourceType,
                'resource_id'      => $resourceId,
                'ip_address'       => $request['ip'] ?? null,
                'request_body'     => self::sanitizeBody($request['body'] ?? []),
                'idempotency_key'  => $request['headers']['X-Idempotency-Key']
                    ?? $request['headers']['x-idempotency-key']
                    ?? null,
                'expires_at'       => $expiresAt,
            ]);
        } catch (\Exception) {
            // Audit log failure must never break the API response
        }
    }

    /**
     * Remove sensitive fields from request body before logging.
     */
    private static function sanitizeBody(array $body): array
    {
        $sensitiveKeys = ['password', 'secret', 'token', 'api_key', 'psk', 'credential'];
        array_walk_recursive($body, function (&$value, $key) use ($sensitiveKeys) {
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains(strtolower((string)$key), $sensitive)) {
                    $value = '[REDACTED]';
                    return;
                }
            }
        });
        return $body;
    }
}
