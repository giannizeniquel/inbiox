<?php

declare(strict_types=1);

namespace App\Automatizacion;

use App\Conversacion\EstadoDeEntrega;
use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Compartido\Graph\GraphClient;

use App\Agente\Agente;
use App\Conversacion\Conversacion;
use App\Compartido\RecolectorDeEventos;
use App\Conversacion\MessageCreatedEvent;
use App\Conversacion\Mensaje;
use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\TipoDeRemitente;
use App\Conversacion\PoliticaDeVentana;
use App\Compartido\EventoProcesado;
use App\Ticket\Ticket;
use App\Compartido\Graph\GraphApiException;
use App\Agente\Repository\AgenteRepository;
use App\Compartido\Repository\EventoProcesadoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Servicio fire-and-forget que envía respuestas automáticas cuando se cumplen las condiciones.
 *
 * Condiciones (deben cumplirse todas):
 * 1. El agente destino tiene auto_reply_enabled = true
 * 2. El agente destino tiene un auto_reply_message no vacío
 * 3. El agente destino NO está disponible en este momento (no disponible/fuera de turno)
 * 4. No se envió una respuesta automática reciente a este contacto (límite de 4 h)
 *
 * Si alguna condición falla, el método vuelve sin hacer nada (fire-and-forget).
 * Las excepciones de GraphClient se capturan y se registran en el log — nunca se propagan.
 */
final class RespuestaAutomaticaService
{
    private const HORAS_DE_VENTANA_DE_RESPUESTA_AUTOMATICA = 2;

    public function __construct(
        private readonly AgenteRepository $agentRepo,
        private readonly DisponibilidadDeAgenteService $availabilityService,
        private readonly GraphClient $graphClient,
        private readonly EventoProcesadoRepository $eventoProcesadoRepository,
        private readonly RecolectorDeEventos $recolectorDeEventos,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly PoliticaDeVentana $windowPolicy,
    ) {}

    /**
     * Intenta enviar una respuesta automática para el ticket dado.
     *
     * Es un método fire-and-forget: captura todas las excepciones y las registra en el log,
     * sin relanzarlas nunca. Se llama DESPUÉS del commit de la transacción del mensaje entrante.
     */
    public function enviarSiCorresponde(Ticket $ticket): void
    {
        try {
            $this->ejecutarEnvioSiCorresponde($ticket);
        } catch (\Throwable $e) {
            $this->logger->warning('AutoReplyService::maybeSend unexpected error: {message}', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticket->getId(),
                'exception' => $e,
            ]);
        }
    }

    private function ejecutarEnvioSiCorresponde(Ticket $ticket): void
    {
        // 1. Resuelve el agente destino
        $agente = $this->buscarAgenteDestino($ticket);
        if ($agente === null) {
            return; // No hay agente elegible
        }

        // 2. Verifica que la respuesta automática esté habilitada y tenga mensaje
        if (!$agente->isRespuestaAutomaticaActiva()) {
            return;
        }

        $mensajeDeRespuestaAutomatica = $agente->getMensajeDeRespuestaAutomatica();
        if ($mensajeDeRespuestaAutomatica === null || \trim($mensajeDeRespuestaAutomatica) === '') {
            return;
        }

        // 3. Verifica que el agente NO esté disponible (se envía cuando no está disponible)
        $now = new \DateTimeImmutable('now');
        if ($this->availabilityService->isDisponible($agente, $now)) {
            return; // El agente está disponible — no se envía la respuesta automática
        }

        // 4. Verificación del límite de frecuencia
        $contacto = $ticket->getContacto();
        if ($contacto === null) {
            return;
        }

        $contactoId = (int) $contacto->getId();
        $rateLimitKey = \sprintf('auto-reply:%d', $contactoId);

        if ($this->hasRespuestaAutomaticaReciente($rateLimitKey)) {
            $this->logger->info('AutoReply rate-limited for contact {contactId}', [
                'contactId' => $contactoId,
                'ticket_id' => $ticket->getId(),
            ]);
            return;
        }

        // 5. Envía el mensaje
        $phoneNumberId = $ticket->getPhoneNumberId();
        $telefonoDestino = $contacto->getTelefono();

        if ($telefonoDestino === null || $telefonoDestino === '') {
            $this->logger->warning('AutoReply skipped: contact has no phone number', [
                'contactId' => $contactoId,
                'ticket_id' => $ticket->getId(),
            ]);
            return;
        }

        // Guarda de la ventana de conversación de 24 h de WhatsApp: una respuesta automática es fire-and-
        // forget, así que una ventana vencida no es un error — solo un return
        // temprano con una línea de log, nunca un throw (design.md, tabla de llamadores).
        if (!$this->windowPolicy->isAbiertaParaEnviar($ticket->getUltimoMensajeEntranteAt(), new \DateTimeImmutable())) {
            $this->logger->info('AutoReply skipped: conversation window has expired', [
                'contactId' => $contactoId,
                'ticket_id' => $ticket->getId(),
            ]);
            return;
        }

        try {
            $graphResponse = $this->graphClient->sendMessage($telefonoDestino, $mensajeDeRespuestaAutomatica, $phoneNumberId);
        } catch (GraphApiException $e) {
            $this->logger->error('AutoReply Graph API error: {message}', [
                'message' => $e->getMessage(),
                'graphCode' => $e->getGraphCode(),
                'contactId' => $contactoId,
                'ticket_id' => $ticket->getId(),
            ]);
            return; // Fire-and-forget: se descarta la excepción
        }

        // 6. Crea la entidad Message local para que la respuesta automática aparezca en la conversación
        $wamid = $graphResponse['messages'][0]['id'] ?? null;
        $conversacion = $ticket->getConversacion();
        $mensajeSaliente = null;
        if ($conversacion !== null) {
            $mensajeSaliente = new Mensaje();
            $mensajeSaliente->setConversacion($conversacion);
            $mensajeSaliente->setDireccion(DireccionDeMensaje::SALIENTE->value);
            $mensajeSaliente->setContenido($mensajeDeRespuestaAutomatica);
            if ($wamid !== null) {
                $mensajeSaliente->setExternalId($wamid);
            }
            $mensajeSaliente->setEstadoDeEntrega(EstadoDeEntrega::ENVIADO->value);
            $mensajeSaliente->setAgenteRemitenteId($agente->getId());
            $mensajeSaliente->setTipoDeRemitente(TipoDeRemitente::AUTOMATICO);
            $this->em->persist($mensajeSaliente);

            // Actualiza la marca de tiempo de la conversación
            $conversacion->setUltimoMensajeAt($mensajeSaliente->getCreadoAt());
            $conversacion->setActualizadoAt(new \DateTimeImmutable());
        }

        // 7. Upsert de la clave de límite de frecuencia (actualiza la marca de tiempo o inserta una nueva)
        $existingEvent = $this->eventoProcesadoRepository->findByEventoId($rateLimitKey);
        if ($existingEvent !== null) {
            $existingEvent->setProcesadoAt(new \DateTimeImmutable('now'));
        } else {
            $eventoProcesado = new EventoProcesado($rateLimitKey);
            $this->em->persist($eventoProcesado);
        }
        $this->em->flush();

        // 8. Emite un evento de outbox para que el mensaje aparezca en tiempo real mediante Mercure
        if ($mensajeSaliente !== null) {
            $this->recolectorDeEventos->recolectar(new MessageCreatedEvent(
                eventId: new Ulid(),
                ticketId: $ticket->getId(),
                occurredAt: new \DateTimeImmutable(),
                seq: $ticket->incrementLastEventSeq(),
                version: 1,
                direction: DireccionDeMensaje::SALIENTE,
                messageId: (int) $mensajeSaliente->getId(),
                content: $mensajeDeRespuestaAutomatica,
                senderName: $agente->getNombre(),
            ));
        }

        $this->logger->info('AutoReply sent to contact {contactId}', [
            'contactId' => $contactoId,
            'ticket_id' => $ticket->getId(),
        ]);
    }

    /**
     * Resuelve qué agente debe enviar la respuesta automática.
     *
     * Prioridad:
     * 1. Agente asignado (si lo hay)
     * 2. Primer agente activo del espacio de trabajo que coincida con el phoneNumberId del ticket
     *    con auto_reply_enabled = true
     */
    private function buscarAgenteDestino(Ticket $ticket): ?Agente
    {
        $agenteAsignadoId = $ticket->getAgenteAsignadoId();

        if ($agenteAsignadoId !== null) {
            $agente = $this->agentRepo->findById($agenteAsignadoId);
            if ($agente !== null && $agente->isActivo()) {
                return $agente;
            }
        }

        // Alternativa: busca el primer agente activo con auto_reply_enabled
        // Usa el phoneNumberId del ticket para acotar al espacio de trabajo correcto
        $phoneNumberId = $ticket->getPhoneNumberId();
        $agentesActivos = $this->agentRepo->findActivos();
        foreach ($agentesActivos as $candidato) {
            if ($candidato->isRespuestaAutomaticaActiva()) {
                // Verifica si este agente está en un espacio de trabajo que coincida con el phoneNumberId del ticket
                if ($phoneNumberId !== null) {
                    foreach ($candidato->getEspaciosDeTrabajo() as $ws) {
                        if ($ws->getPhoneNumberId() === $phoneNumberId) {
                            return $candidato;
                        }
                    }
                } else {
                    return $candidato;
                }
            }
        }

        return null;
    }

    /**
     * Verifica si se envió una respuesta automática reciente para la clave de límite de frecuencia dada.
     */
    private function hasRespuestaAutomaticaReciente(string $rateLimitKey): bool
    {
        $existing = $this->eventoProcesadoRepository->findByEventoId($rateLimitKey);
        if ($existing === null) {
            return false;
        }

        $finDeVentana = $existing->getProcesadoAt()->modify(
            \sprintf('+%d hours', self::HORAS_DE_VENTANA_DE_RESPUESTA_AUTOMATICA)
        );

        return $finDeVentana >= new \DateTimeImmutable('now');
    }
}
