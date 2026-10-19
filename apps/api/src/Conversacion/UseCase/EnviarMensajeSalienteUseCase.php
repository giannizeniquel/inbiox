<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\NotFoundException;
use App\Conversacion\TipoDeAdjunto;
use App\Conversacion\TelefonoDeDestinatarioNoEncontradoException;
use App\Ticket\Repository\TicketRepository;

/**
 * Sends an agent's text or media reply on a ticket through WhatsApp.
 *
 * The message is stored (with its assignment and status effects) before the
 * Graph call, so it stays stored when the recipient cannot be resolved or
 * Graph rejects the send.
 */
final class EnviarMensajeSalienteUseCase
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly GraphClient $graphClient,
        private readonly ProcesarMensajeSalienteUseCase $procesarMensajeSalienteUseCase,
        private readonly MarcarMensajeSalienteEnviadoUseCase $marcarMensajeSalienteEnviado,
    ) {}

    /**
     * The media fields are passed as the client sent them; a media send
     * needs both `$mediaId` and `$mimeType`, otherwise the content goes as
     * text.
     *
     * @return array{messageId: mixed, externalId: ?string}
     *
     * @throws NotFoundException               the ticket disappeared after the message was stored
     * @throws TelefonoDeDestinatarioNoEncontradoException
     * @throws GraphApiException
     */
    public function execute(
        int $ticketId,
        string $contenido,
        int $agenteId,
        bool $remitenteEsPrivilegiado,
        mixed $mediaId = null,
        mixed $mimeType = null,
        mixed $leyenda = null,
        mixed $mediaKey = null,
        mixed $filename = null,
        mixed $tamanio = null,
    ): array {
        $isMedia = $mediaId !== null && $mimeType !== null;

        $metadatosDeAdjunto = null;
        if ($isMedia) {
            $metadatosDeAdjunto = [
                'providerId' => $mediaId,
                'mimeType' => $mimeType,
                'key' => $mediaKey,
                'caption' => $leyenda,
                'filename' => $filename,
                'size' => $tamanio,
                'kind' => TipoDeAdjunto::fromMime($mimeType)->value,
            ];
        }

        // Creates the Message and applies the assignment and status changes.
        $result = $this->procesarMensajeSalienteUseCase->procesarMensajeSaliente(
            ticketId: $ticketId,
            contenido: $contenido,
            agenteId: $agenteId,
            metadatosDeAdjunto: $metadatosDeAdjunto,
            remitenteEsPrivilegiado: $remitenteEsPrivilegiado,
        );

        $ticket = $this->ticketRepository->find($ticketId);
        if (!$ticket) {
            throw new NotFoundException('Ticket not found');
        }

        $telefono = $ticket->telefonoDestinatario();

        // Each ticket replies from the WhatsApp number it came in on (CAP-7 / META-018).
        $phoneNumberId = $ticket->getPhoneNumberId();

        if ($isMedia) {
            $graphResponse = $this->graphClient->sendMediaMessage($telefono, $mediaId, $mimeType, $leyenda ?? $contenido, $phoneNumberId, $filename);
        } else {
            $graphResponse = $this->graphClient->sendMessage($telefono, $contenido, $phoneNumberId);
        }

        $wamid = $graphResponse['messages'][0]['id'] ?? null;

        if ($wamid !== null && !empty($result['message_id'])) {
            $this->marcarMensajeSalienteEnviado->execute($result['message_id'], $wamid);
        }

        return [
            'messageId' => $result['message_id'] ?? null,
            'externalId' => $wamid,
        ];
    }
}
