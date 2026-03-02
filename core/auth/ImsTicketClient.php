<?php

declare(strict_types=1);

namespace NMS\Core\Auth;

/**
 * IMS Ticket Client — full Phase 6 implementation.
 *
 * Creates and manages tickets in the IMS ticket system via M2M API calls.
 * NMS does NOT have its own ticketing system; all human-actionable items
 * are created as tickets in IMS and worked from a single engineer queue.
 *
 * NMS ticket types (registered in IMS):
 *   nms_intervention        — Provisioning step requires manual action
 *   nms_drift               — Drift detected, requires operator approval
 *   nms_provision_failure   — All compensation steps exhausted
 *   nms_device_unreachable  — Device unreachable for >15 min (circuit breaker open)
 *
 * Authentication:
 *   Uses M2MTokenHelper::issueForIms() to obtain a NMS→IMS M2M token.
 *   Token has aud: "ims-m2m" and permissions: ["ims.ticket.create", "ims.ticket.update", "ims.server.read"].
 *
 * Configuration:
 *   IMS_API_URL env var (or config['ims']['api_url'])
 *   IMS_M2M_CLIENT_ID env var (or config['ims']['m2m_client_id'])
 *
 * Error handling:
 *   Throws \RuntimeException on HTTP errors or IMS-side failures.
 *   Callers (CompensationRunner, DriftDetector) should catch and log — ticket
 *   creation failure must never block the primary operation.
 */
class ImsTicketClient
{
    private M2MTokenHelper $m2mHelper;
    private string $imsApiUrl;

    private const VALID_TYPES = [
        'nms_intervention',
        'nms_drift',
        'nms_provision_failure',
        'nms_device_unreachable',
    ];

    public function __construct(M2MTokenHelper $m2mHelper, ?array $config = null)
    {
        $config ??= require dirname(__DIR__) . '/config/app.php';
        $this->m2mHelper  = $m2mHelper;
        $this->imsApiUrl  = rtrim((string)($config['ims']['api_url'] ?? ''), '/');
    }

    /**
     * Create a ticket in the IMS ticket system.
     *
     * @param  string $type      Ticket type — must be one of VALID_TYPES
     * @param  string $title     Short ticket title (shown in IMS queue)
     * @param  string $body      Full ticket description / steps to resolve
     * @param  array  $sourceRef Reference to the NMS entity that generated this ticket
     *                           e.g. ['job_id' => '...', 'step' => 4, 'device_id' => '...']
     * @return string            IMS-assigned ticket ID (UUID)
     * @throws \RuntimeException if ticket creation fails or IMS returns an error
     */
    public function createTicket(string $type, string $title, string $body, array $sourceRef): string
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid ticket type '{$type}'. Allowed: " . implode(', ', self::VALID_TYPES)
            );
        }

        $payload = [
            'type'           => $type,
            'title'          => $title,
            'body'           => $body,
            'priority'       => $this->priorityForType($type),
            'source_system'  => 'nms',
            'source_ref'     => $sourceRef,
            'assigned_group' => 'network-ops',
        ];

        $response = $this->post('/api/tickets', $payload);
        $ticketId = (string)($response['ticket_id'] ?? $response['id'] ?? '');

        if ($ticketId === '') {
            throw new \RuntimeException(
                'IMS ticket creation returned no ticket ID. Response: ' . json_encode($response)
            );
        }

        return $ticketId;
    }

    /**
     * Update an existing IMS ticket.
     *
     * @param string $ticketId  IMS ticket UUID
     * @param array  $updates   Fields to update: status?, comment?, assigned_to?, priority?
     * @throws \RuntimeException on HTTP error
     */
    public function updateTicket(string $ticketId, array $updates): void
    {
        if ($ticketId === '') {
            throw new \InvalidArgumentException('ticketId cannot be empty');
        }
        $this->put("/api/tickets/{$ticketId}", $updates);
    }

    /**
     * Retrieve a ticket from IMS.
     *
     * @param  string $ticketId  IMS ticket UUID
     * @return array             Full ticket document from IMS
     * @throws \RuntimeException if ticket not found or HTTP error
     */
    public function getTicket(string $ticketId): array
    {
        if ($ticketId === '') {
            throw new \InvalidArgumentException('ticketId cannot be empty');
        }
        return $this->get("/api/tickets/{$ticketId}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    private function put(string $path, array $payload): array
    {
        return $this->request('PUT', $path, $payload);
    }

    private function get(string $path): array
    {
        return $this->request('GET', $path, []);
    }

    /**
     * Execute an HTTP request to the IMS API with a fresh M2M token.
     */
    private function request(string $method, string $path, array $payload): array
    {
        if ($this->imsApiUrl === '') {
            throw new \RuntimeException(
                'IMS API URL is not configured (IMS_API_URL env var or config.ims.api_url)'
            );
        }

        $token = $this->m2mHelper->issueForIms();
        $url   = $this->imsApiUrl . $path;

        $curlHandle = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        } elseif ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $options[CURLOPT_POSTFIELDS]    = json_encode($payload);
        } elseif ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($curlHandle, $options);

        $responseBody = curl_exec($curlHandle);
        $httpCode     = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($curlHandle);
        curl_close($curlHandle);

        if ($responseBody === false || $curlError !== '') {
            throw new \RuntimeException("IMS API request failed (curl): {$curlError}");
        }

        $decoded = json_decode((string)$responseBody, true);

        if ($httpCode >= 400) {
            $errorMsg = (string)($decoded['message'] ?? $decoded['error'] ?? $responseBody);
            throw new \RuntimeException(
                "IMS API returned HTTP {$httpCode}: {$errorMsg}"
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function priorityForType(string $type): string
    {
        return match ($type) {
            'nms_provision_failure'   => 'critical',
            'nms_intervention'        => 'high',
            'nms_device_unreachable'  => 'high',
            'nms_drift'               => 'medium',
            default                   => 'medium',
        };
    }
}
