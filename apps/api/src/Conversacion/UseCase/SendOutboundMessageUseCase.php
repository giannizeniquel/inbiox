<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\NotFoundException;
use App\Conversacion\MediaKind;
use App\Conversacion\RecipientPhoneNotFoundException;
use App\Ticket\Repository\TicketRepository;

/**
 * Sends an agent's text or media reply on a ticket through WhatsApp.
 *
 * The message is stored (with its assignment and status effects) before the
 * Graph call, so it stays stored when the recipient cannot be resolved or
 * Graph rejects the send.
 */
final class SendOutboundMessageUseCase
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly GraphClient $graphClient,
        private readonly HandleOutboundMessageUseCase $outboundUseCase,
        private readonly MarkOutboundMessageSentUseCase $markOutboundMessageSent,
    ) {}

    /**
     * The media fields are passed as the client sent them; a media send
     * needs both `$mediaId` and `$mimeType`, otherwise the content goes as
     * text.
     *
     * @return array{messageId: mixed, externalId: ?string}
     *
     * @throws NotFoundException               the ticket disappeared after the message was stored
     * @throws RecipientPhoneNotFoundException
     * @throws GraphApiException
     */
    public function execute(
        int $ticketId,
        string $content,
        int $agentId,
        bool $senderIsPrivileged,
        mixed $mediaId = null,
        mixed $mimeType = null,
        mixed $caption = null,
        mixed $mediaKey = null,
        mixed $filename = null,
        mixed $size = null,
    ): array {
        $isMedia = $mediaId !== null && $mimeType !== null;

        $mediaMetadata = null;
        if ($isMedia) {
            $mediaMetadata = [
                'providerId' => $mediaId,
                'mimeType' => $mimeType,
                'key' => $mediaKey,
                'caption' => $caption,
                'filename' => $filename,
                'size' => $size,
                'kind' => MediaKind::fromMime($mimeType)->value,
            ];
        }

        // Creates the Message and applies the assignment and status changes.
        $result = $this->outboundUseCase->processOutboundMessage(
            ticketId: $ticketId,
            content: $content,
            agentId: $agentId,
            mediaMetadata: $mediaMetadata,
            senderIsPrivileged: $senderIsPrivileged,
        );

        $ticket = $this->ticketRepository->find($ticketId);
        if (!$ticket) {
            throw new NotFoundException('Ticket not found');
        }

        $phone = $ticket->telefonoDestinatario();

        // Each ticket replies from the WhatsApp number it came in on (CAP-7 / META-018).
        $phoneNumberId = $ticket->getPhoneNumberId();

        if ($isMedia) {
            $graphResponse = $this->graphClient->sendMediaMessage($phone, $mediaId, $mimeType, $caption ?? $content, $phoneNumberId, $filename);
        } else {
            $graphResponse = $this->graphClient->sendMessage($phone, $content, $phoneNumberId);
        }

        $wamid = $graphResponse['messages'][0]['id'] ?? null;

        if ($wamid !== null && !empty($result['message_id'])) {
            $this->markOutboundMessageSent->execute($result['message_id'], $wamid);
        }

        return [
            'messageId' => $result['message_id'] ?? null,
            'externalId' => $wamid,
        ];
    }
}
