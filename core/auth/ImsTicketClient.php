<?php

declare(strict_types=1);

namespace NMS\Core\Auth;

/**
 * IMS Ticket Client — stub only.
 * Full implementation in Phase 6.
 *
 * Creates and updates tickets in the IMS ticket system via M2M API calls.
 * Ticket types: nms_intervention, nms_drift, nms_provision_failure, nms_device_unreachable
 */
class ImsTicketClient
{
    private M2MTokenHelper $m2mHelper;
    private string $imsApiUrl;

    public function __construct(M2MTokenHelper $m2mHelper, ?array $config = null)
    {
        $config ??= require dirname(__DIR__) . '/config/app.php';
        $this->m2mHelper = $m2mHelper;
        $this->imsApiUrl = rtrim($config['ims']['api_url'] ?? '', '/');
    }

    /**
     * Create a ticket in IMS.
     *
     * @param  string $type      Ticket type (nms_intervention|nms_drift|nms_provision_failure|nms_device_unreachable)
     * @param  string $title     Ticket title
     * @param  string $body      Ticket description body
     * @param  array  $sourceRef Reference to the NMS entity (e.g. ['type' => 'device', 'id' => '...'])
     * @return string            IMS ticket ID
     */
    public function createTicket(string $type, string $title, string $body, array $sourceRef): string
    {
        // Full implementation in Phase 6
        throw new \RuntimeException('ImsTicketClient::createTicket() not yet implemented (Phase 6)');
    }

    /**
     * Update an existing IMS ticket.
     */
    public function updateTicket(string $ticketId, array $updates): void
    {
        // Full implementation in Phase 6
        throw new \RuntimeException('ImsTicketClient::updateTicket() not yet implemented (Phase 6)');
    }

    /**
     * Get a ticket from IMS.
     */
    public function getTicket(string $ticketId): array
    {
        // Full implementation in Phase 6
        throw new \RuntimeException('ImsTicketClient::getTicket() not yet implemented (Phase 6)');
    }
}
