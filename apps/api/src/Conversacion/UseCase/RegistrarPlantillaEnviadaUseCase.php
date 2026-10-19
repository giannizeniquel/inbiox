<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Conversacion\Mensaje;
use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\TipoDeRemitente;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists a template that Graph accepted as a new outbound message, with
 * its WAMID as externalId for webhook correlation.
 */
final class RegistrarPlantillaEnviadaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $parameters the template parameters sent to Graph
     */
    public function execute(Ticket $ticket, string $templateName, array $parameters, string $wamid, ?int $senderAgentId): void
    {
        $content = "Template: {$templateName}" . (!empty($parameters) ? ' (' . implode(', ', $parameters) . ')' : '');
        $message = new Mensaje();
        $message->setContenido($content);
        $message->setExternalId($wamid);
        $message->setDireccion(DireccionDeMensaje::SALIENTE->value);
        $message->setEstadoDeEntrega('sent');
        $message->setActualizadoAt(new \DateTimeImmutable());
        $message->setAgenteRemitenteId($senderAgentId);
        $message->setTipoDeRemitente(TipoDeRemitente::AGENTE);
        if ($ticket->getConversacion()) {
            $message->setConversacion($ticket->getConversacion());
        }
        $this->entityManager->persist($message);
        $this->entityManager->flush();
    }
}
