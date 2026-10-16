<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\NotFoundException;
use App\Conversacion\RecipientPhoneNotFoundException;
use App\Ticket\Repository\TicketRepository;

/**
 * Sends a Meta-approved template on a ticket through WhatsApp.
 *
 * Unlike a reply, it does not check the 24 h window and does not change the
 * ticket's status or assignee. It stores a message only when Graph returns
 * a WAMID.
 */
final class SendTemplateUseCase
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly GraphClient $graphClient,
        private readonly RecordSentTemplateUseCase $recordSentTemplate,
    ) {}

    /**
     * `$templateName` and `$language` are passed as the client sent them.
     * `$resolveSenderAgentId` is called only after a send that returned a
     * WAMID, because resolving the agent can create one.
     *
     * @param array<mixed>        $parameters
     * @param \Closure(): ?int    $resolveSenderAgentId
     *
     * @return ?string the WAMID
     *
     * @throws NotFoundException
     * @throws RecipientPhoneNotFoundException
     * @throws GraphApiException
     */
    public function execute(
        int $ticketId,
        mixed $templateName,
        mixed $language,
        array $parameters,
        \Closure $resolveSenderAgentId,
    ): ?string {
        $ticket = $this->ticketRepository->find($ticketId);
        if (!$ticket) {
            throw new NotFoundException('Ticket not found');
        }

        $phone = $ticket->recipientPhone();

        // Each ticket replies from the WhatsApp number it came in on (CAP-7 / META-018).
        $graphResponse = $this->graphClient->sendTemplate($phone, $templateName, $language, $parameters, $ticket->getPhoneNumberId());

        $wamid = $graphResponse['messages'][0]['id'] ?? null;

        // Stored with the WAMID as external id so status webhooks can find it.
        if ($wamid !== null) {
            $this->recordSentTemplate->execute($ticket, $templateName, $parameters, $wamid, $resolveSenderAgentId());
        }

        return $wamid;
    }
}
