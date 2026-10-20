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
 * Envía por WhatsApp la respuesta de texto o con adjunto de un agente en un ticket.
 *
 * El mensaje se guarda (con sus efectos de asignación y estado) antes de la
 * llamada a Graph, así que queda guardado cuando no se puede resolver el destinatario o
 * Graph rechaza el envío.
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
     * Los campos del adjunto se pasan tal como los envió el cliente; un envío con
     * adjunto necesita tanto `$mediaId` como `$mimeType`; si no, el contenido va como
     * texto.
     *
     * @return array{messageId: mixed, externalId: ?string}
     *
     * @throws NotFoundException               el ticket desapareció después de guardar el mensaje
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

        // Crea el mensaje y aplica los cambios de asignación y estado.
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

        // Cada ticket responde desde el número de WhatsApp por el que entró (CAP-7 / META-018).
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
