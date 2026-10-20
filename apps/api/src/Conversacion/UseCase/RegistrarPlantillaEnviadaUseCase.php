<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Conversacion\EstadoDeEntrega;
use App\Conversacion\Mensaje;
use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\TipoDeRemitente;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persiste una plantilla que Graph aceptó como un nuevo mensaje saliente, con
 * su WAMID como externalId para correlacionarla con los webhooks.
 */
final class RegistrarPlantillaEnviadaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $parametros los parámetros de la plantilla enviados a Graph
     */
    public function execute(Ticket $ticket, string $nombreDePlantilla, array $parametros, string $wamid, ?int $agenteRemitenteId): void
    {
        $contenido = "Template: {$nombreDePlantilla}" . (!empty($parametros) ? ' (' . implode(', ', $parametros) . ')' : '');
        $mensaje = new Mensaje();
        $mensaje->setContenido($contenido);
        $mensaje->setExternalId($wamid);
        $mensaje->setDireccion(DireccionDeMensaje::SALIENTE->value);
        $mensaje->setEstadoDeEntrega(EstadoDeEntrega::ENVIADO->value);
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
