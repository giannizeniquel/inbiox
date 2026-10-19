<?php

declare(strict_types=1);

namespace App\Automatizacion;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Compartido\Graph\GraphClient;

use App\Agente\Agente;
use App\Conversacion\Conversacion;
use App\Compartido\EventCollector;
use App\Conversacion\MessageCreatedEvent;
use App\Conversacion\Mensaje;
use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\TipoDeRemitente;
use App\Conversacion\PoliticaDeVentana;
use App\Compartido\ProcessedEvent;
use App\Ticket\Ticket;
use App\Compartido\Graph\GraphApiException;
use App\Agente\Repository\AgenteRepository;
use App\Compartido\Repository\ProcessedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Fire-and-forget service to send auto-reply messages when conditions are met.
 *
 * Conditions (all must pass):
 * 1. Target agent has auto_reply_enabled = true
 * 2. Target agent has a non-empty auto_reply_message
 * 3. Target agent is NOT currently available (unavailable/off-shift)
 * 4. No recent auto-reply was sent to this contact (4h rate limit)
 *
 * If any condition fails, the method returns silently (fire-and-forget).
 * Exceptions from GraphClient are caught and logged — never propagated.
 */
final class RespuestaAutomaticaService
{
    private const HORAS_DE_VENTANA_DE_RESPUESTA_AUTOMATICA = 2;

    public function __construct(
        private readonly AgenteRepository $agentRepo,
        private readonly DisponibilidadDeAgenteService $availabilityService,
        private readonly GraphClient $graphClient,
        private readonly ProcessedEventRepository $processedEventRepo,
        private readonly EventCollector $eventCollector,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly PoliticaDeVentana $windowPolicy,
    ) {}

    /**
     * Attempt to send an auto-reply for the given ticket.
     *
     * This is a fire-and-forget method: it catches all exceptions and logs them,
     * never re-throwing. Called AFTER the inbound message transaction commits.
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
        // 1. Resolve target agent
        $agente = $this->buscarAgenteDestino($ticket);
        if ($agente === null) {
            return; // No eligible agent
        }

        // 2. Check auto-reply is enabled and has a message
        if (!$agente->isRespuestaAutomaticaActiva()) {
            return;
        }

        $mensajeDeRespuestaAutomatica = $agente->getMensajeDeRespuestaAutomatica();
        if ($mensajeDeRespuestaAutomatica === null || \trim($mensajeDeRespuestaAutomatica) === '') {
            return;
        }

        // 3. Check agent is NOT available (we send when they're unavailable)
        $now = new \DateTimeImmutable('now');
        if ($this->availabilityService->isDisponible($agente, $now)) {
            return; // Agent is available — don't send auto-reply
        }

        // 4. Rate limit check
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

        // 5. Send the message
        $phoneNumberId = $ticket->getPhoneNumberId();
        $telefonoDestino = $contacto->getTelefono();

        if ($telefonoDestino === null || $telefonoDestino === '') {
            $this->logger->warning('AutoReply skipped: contact has no phone number', [
                'contactId' => $contactoId,
                'ticket_id' => $ticket->getId(),
            ]);
            return;
        }

        // WhatsApp 24h conversation-window guard: an auto-reply is fire-and-
        // forget, so an expired window is not an error — just an early
        // return with a log line, never a throw (design.md caller table).
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
            return; // Fire-and-forget: swallow the exception
        }

        // 6. Create local Message entity so the auto-reply appears in the conversation
        $wamid = $graphResponse['messages'][0]['id'] ?? null;
        $conversacion = $ticket->getConversacion();
        $mensajeSaliente = null;
        if ($conversacion !== null) {
            $mensajeSaliente = new Mensaje();
            $mensajeSaliente->setConversacion($conversacion);
            $mensajeSaliente->setDireccion('outbound');
            $mensajeSaliente->setContenido($mensajeDeRespuestaAutomatica);
            if ($wamid !== null) {
                $mensajeSaliente->setExternalId($wamid);
            }
            $mensajeSaliente->setEstadoDeEntrega('sent');
            $mensajeSaliente->setAgenteRemitenteId($agente->getId());
            $mensajeSaliente->setTipoDeRemitente(TipoDeRemitente::AUTOMATICO);
            $this->em->persist($mensajeSaliente);

            // Update conversation timestamp
            $conversacion->setUltimoMensajeAt($mensajeSaliente->getCreadoAt());
            $conversacion->setActualizadoAt(new \DateTimeImmutable());
        }

        // 7. Upsert rate-limit key (update timestamp or insert new)
        $existingEvent = $this->processedEventRepo->findByEventId($rateLimitKey);
        if ($existingEvent !== null) {
            $existingEvent->setProcessedAt(new \DateTimeImmutable('now'));
        } else {
            $processedEvent = new ProcessedEvent($rateLimitKey);
            $this->em->persist($processedEvent);
        }
        $this->em->flush();

        // 8. Emit outbox event so the message appears in real-time via Mercure
        if ($mensajeSaliente !== null) {
            $this->eventCollector->collect(new MessageCreatedEvent(
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
     * Resolve which agent should send the auto-reply.
     *
     * Priority:
     * 1. Assigned agent (if set)
     * 2. First active agent in the workspace matching the ticket's phoneNumberId
     *    with auto_reply_enabled = true
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

        // Fallback: find first active agent with auto_reply_enabled
        // Use the ticket's phoneNumberId to scope to the right workspace
        $phoneNumberId = $ticket->getPhoneNumberId();
        $agentesActivos = $this->agentRepo->findActivos();
        foreach ($agentesActivos as $candidato) {
            if ($candidato->isRespuestaAutomaticaActiva()) {
                // Check if this agent is in a workspace matching the ticket's phoneNumberId
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
     * Check if a recent auto-reply was sent for the given rate-limit key.
     */
    private function hasRespuestaAutomaticaReciente(string $rateLimitKey): bool
    {
        $existing = $this->processedEventRepo->findByEventId($rateLimitKey);
        if ($existing === null) {
            return false;
        }

        $finDeVentana = $existing->getProcessedAt()->modify(
            \sprintf('+%d hours', self::HORAS_DE_VENTANA_DE_RESPUESTA_AUTOMATICA)
        );

        return $finDeVentana >= new \DateTimeImmutable('now');
    }
}
