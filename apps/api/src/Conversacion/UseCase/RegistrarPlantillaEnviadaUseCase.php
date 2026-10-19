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
     * @param array<mixed> $parametros the template parameters sent to Graph
     */
    public function execute(Ticket $ticket, string $nombreDePlantilla, array $parametros, string $wamid, ?int $agenteRemitenteId): void
    {
        $contenido = "Template: {$nombreDePlantilla}" . (!empty($parametros) ? ' (' . implode(', ', $parametros) . ')' : '');
        $mensaje = new Mensaje();
        $mensaje->setContenido($contenido);
        $mensaje->setExternalId($wamid);
        $mensaje->setDireccion(DireccionDeMensaje::SALIENTE->value);
        $mensaje->setEstadoDeEntrega('sent');
        $mensaje->setActualizadoAt(new \DateTimeImmutable());
        $mensaje->setAgenteRemitenteId($agenteRemitenteId);
        $mensaje->setTipoDeRemitente(TipoDeRemitente::AGENTE);
        if ($ticket->getConversacion()) {
            $mensaje->setConversacion($ticket->getConversacion());
        }
        $this->entityManager->persist($mensaje);
        $this->entityManager->flush();
    }
}
