<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Compartido\Graph\GraphApiException;
use App\Compartido\Graph\GraphClient;
use App\Compartido\NotFoundException;
use App\Conversacion\TelefonoDeDestinatarioNoEncontradoException;
use App\Ticket\Repository\TicketRepository;

/**
 * Envía por WhatsApp una plantilla aprobada por Meta en un ticket.
 *
 * A diferencia de una respuesta, no verifica la ventana de 24 h ni cambia el
 * estado ni el responsable del ticket. Guarda un mensaje solo cuando Graph devuelve
 * un WAMID.
 */
final class EnviarPlantillaUseCase
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly GraphClient $graphClient,
        private readonly RegistrarPlantillaEnviadaUseCase $registrarPlantillaEnviada,
    ) {}

    /**
     * `$nombreDePlantilla` y `$idioma` se pasan tal como los envió el cliente.
     * `$resolverAgenteRemitenteId` se llama solo después de un envío que devolvió un
     * WAMID, porque resolver el agente puede crear uno.
     *
     * @param array<mixed>        $parametros
     * @param \Closure(): ?int    $resolverAgenteRemitenteId
     *
     * @return ?string el WAMID
     *
     * @throws NotFoundException
     * @throws TelefonoDeDestinatarioNoEncontradoException
     * @throws GraphApiException
     */
    public function execute(
        int $ticketId,
        mixed $nombreDePlantilla,
        mixed $idioma,
        array $parametros,
        \Closure $resolverAgenteRemitenteId,
    ): ?string {
        $ticket = $this->ticketRepository->find($ticketId);
        if (!$ticket) {
            throw new NotFoundException('Ticket not found');
        }

        $telefono = $ticket->telefonoDestinatario();

        // Cada ticket responde desde el número de WhatsApp por el que entró (CAP-7 / META-018).
        $graphResponse = $this->graphClient->sendTemplate($telefono, $nombreDePlantilla, $idioma, $parametros, $ticket->getPhoneNumberId());

        $wamid = $graphResponse['messages'][0]['id'] ?? null;

        // Se guarda con el WAMID como external id para que los webhooks de estado puedan encontrarlo.
        if ($wamid !== null) {
            $this->registrarPlantillaEnviada->execute($ticket, $nombreDePlantilla, $parametros, $wamid, $resolverAgenteRemitenteId());
        }

        return $wamid;
    }
}
