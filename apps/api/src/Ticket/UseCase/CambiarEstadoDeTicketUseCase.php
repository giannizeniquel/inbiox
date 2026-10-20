<?php

namespace App\Ticket\UseCase;

use App\Asignacion\ResponsabilidadDeTicketService;
use App\Agente\Agente;
use App\Asignacion\TipoDeAsignacion;
use App\Ticket\EventoDeTicket;
use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\MaquinaDeEstadosDeTicket;
use App\Ticket\EstadoDeTicket;
use App\Agente\Repository\AgenteRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cambia el estado del ticket siguiendo las reglas de la FSM.
 * Asigna los timestamps de SLA de forma determinista.
 *
 * Al resolver/cerrar, si el ticket tiene responsable, crea una fila de auditoría
 * Asignacion UNASSIGN mediante ResponsabilidadDeTicketService para cerrar el hueco de auditoría (W1).
 */
class CambiarEstadoDeTicketUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketRepository $ticketRepo,
        private MaquinaDeEstadosDeTicket $maquinaDeEstados,
        private ResponsabilidadDeTicketService $assignmentService,
        private AgenteRepository $agentRepository,
    ) {}

    /**
     * Registra la auditoría UNASSIGN si se resuelve/cierra el ticket con responsable.
     */
    private function registrarDesasignacionSiEsResponsable(int $ticketId, string $actor = 'system'): void
    {
        $ticketEntity = $this->ticketRepo->findById($ticketId);
        if ($ticketEntity === null) {
            return;
        }

        $responsableId = $ticketEntity->getAgenteAsignadoId();
        if ($responsableId === null) {
            return;
        }

        $agente = $this->agentRepository->findById($responsableId);
        if ($agente === null) {
            return;
        }

        $this->assignmentService->registrarCambioDeResponsable(
            ticket: $ticketEntity,
            agente: null,
            agenteAnterior: $agente,
            tipo: TipoDeAsignacion::DESASIGNACION,
            asignadoPor: $actor,
        );
    }

    /**
     * Cambia el ticket al nuevo estado con validación de la FSM
     *
     * @param int $ticketId
     * @param string $estadoDestino
     * @param bool $setPrimeraRespuesta - si se asigna first_response_at (respuesta del agente)
     * @param bool $setResuelto - si se asigna resolved_at
     * @return array
     */
    public function execute(
        int $ticketId,
        string $estadoDestino,
        bool $setPrimeraRespuesta = false,
        bool $setResuelto = false,
        string $actor = 'system'
    ): array {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $estadoDestino, $setPrimeraRespuesta, $setResuelto, $actor) {
            // Bloquea el ticket para actualizarlo
            $ticket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            $estadoActual = $ticket['status'];

            // Valida la transición mediante la FSM
            $nuevoEstado = $this->maquinaDeEstados->transicionar($estadoActual, $estadoDestino);

            // Registra la auditoría UNASSIGN en las transiciones de resolución/cierre
            if (($nuevoEstado === EstadoDeTicket::RESUELTO->value || $nuevoEstado === EstadoDeTicket::CERRADO->value)
                && !empty($ticket['assigned_agent_id'])
            ) {
                $this->registrarDesasignacionSiEsResponsable($ticketId, $actor);
                // Hace flush de los cambios de entidades (Asignacion + modificaciones de la entidad ticket)
                // para que formen parte de esta transacción antes del update de estado en SQL crudo
                $this->em->flush();
            }

            // Asigna first_response_at si es una respuesta del agente y todavía no está asignado
            $marcarPrimeraRespuesta = $setPrimeraRespuesta && empty($ticket['first_response_at']);

            // Asigna resolved_at si la transición es a resolved
            $marcarResuelto = $setResuelto && $nuevoEstado === EstadoDeTicket::RESUELTO->value && empty($ticket['resolved_at']);

            // Asigna closed_at al llegar a closed por este punto de entrada
            // genérico (design.md D7) — el camino principal del agente para cerrar
            // su propio ticket, ya que /close es estructuralmente solo para tickets sin responsable (D3).
            $marcarCerrado = $nuevoEstado === EstadoDeTicket::CERRADO->value && empty($ticket['closed_at']);

            $this->ticketRepo->updateEstadoTrasTransicion(
                $ticketId,
                $nuevoEstado,
                $marcarPrimeraRespuesta,
                $marcarResuelto,
                $marcarCerrado,
            );

            return [
                'ticketId' => $ticketId,
                'estadoAnterior' => $estadoActual,
                'estadoNuevo' => $nuevoEstado,
                'previous_assigned_agent_id' => isset($ticket['assigned_agent_id'])
                    ? (int) $ticket['assigned_agent_id'] : null,
                'motivo' => 'success',
            ];
        });
    }

    /**
     * Cierra un ticket porque venció su ventana de conversación de 24 h
     * de WhatsApp (design.md D2/D3).
     *
     * A diferencia de `close()`, esto no exige que el ticket esté `resolved` —
     * la validez se delega por completo a la FSM ampliada
     * (`new|active|waiting|resolved → closed`, tarea 0.9). Escribe una
     * fila `EventoDeTicket(WINDOW_EXPIRED_CLOSED, 'system')` para que el cierre sea
     * distinguible de un cierre humano en el registro de auditoría (capacidad ticket-closure-
     * audit).
     */
    public function cerrarPorVentanaVencida(int $ticketId): array
    {
        return $this->em->getConnection()->transactional(function () use ($ticketId) {
            $ticket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            $estadoActual = $ticket['status'];

            // Validez delegada a la FSM (la ampliación de la tarea 0.9); rechaza
            // un ticket ya cerrado mediante su propia guarda — no hace falta
            // un chequeo especial aquí.
            $nuevoEstado = $this->maquinaDeEstados->transicionar($estadoActual, EstadoDeTicket::CERRADO->value);

            if (!empty($ticket['assigned_agent_id'])) {
                $this->registrarDesasignacionSiEsResponsable($ticketId);
                $this->em->flush();
            }

            $this->ticketRepo->marcarCerrado($ticketId, $nuevoEstado);

            $evento = new EventoDeTicket($ticketId, TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO, 'system');
            $this->em->persist($evento);
            $this->em->flush();

            return [
                'ticketId' => $ticketId,
                'estadoAnterior' => $estadoActual,
                'estadoNuevo' => $nuevoEstado,
                'motivo' => 'window_expired',
            ];
        });
    }

    /**
     * Reabre un ticket cerrado por el proceso de vencimiento de la ventana de 24 h
     * de WhatsApp, en respuesta a un nuevo mensaje entrante del cliente (design.md D1).
     *
     * Este método deliberadamente NUNCA llama a `$this->maquinaDeEstados` — no
     * existe ninguna entrada `closed → *` en `MaquinaDeEstadosDeTicket::TRANSITIONS`, y
     * nunca se agrega ninguna, así que este camino de reapertura es estructuralmente inalcanzable
     * desde cualquier endpoint usado por personas (p. ej. `POST /tickets/{id}/transition`).
     * Tiene su propia guarda independiente, igual que `closeDueToWindowExpiry()`
     * y `close()` ya conviven sin chocar.
     *
     * La guarda 2 (tipo del último evento) se vuelve a comprobar aquí, dentro de la
     * transacción con bloqueo, en lugar de confiar en la búsqueda del repositorio que
     * alimentó a quien llama: las dos llamadas están separadas en el tiempo y un cierre
     * concurrente podría cambiar el motivo entre ambas.
     */
    public function reabrirPorMensajeEntrante(int $ticketId): array
    {
        return $this->em->getConnection()->transactional(function () use ($ticketId) {
            $ticket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            // Guarda 1: solo un ticket cerrado es apto.
            if ($ticket['status'] !== EstadoDeTicket::CERRADO->value) {
                throw new \InvalidArgumentException('Only closed tickets can be reopened: ' . $ticketId);
            }

            // Guarda 2: el último evento del ticket debe ser un cierre por vencimiento
            // de la ventana — se vuelve a comprobar aquí bajo el bloqueo, sin confiar en
            // la búsqueda previa del repositorio hecha por quien llama.
            $ultimoTipo = $this->ticketRepo->findUltimoTipoDeEvento($ticketId);
            if ($ultimoTipo !== TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO) {
                throw new \InvalidArgumentException(
                    'Ticket ' . $ticketId . ' is not eligible for system-triggered reopen'
                );
            }

            $nuevoEstado = EstadoDeTicket::ACTIVO->value;

            $this->ticketRepo->marcarReabierto($ticketId, $nuevoEstado);

            $evento = new EventoDeTicket($ticketId, TipoDeEventoDeTicket::VENTANA_REABIERTO, 'system');
            $this->em->persist($evento);
            $this->em->flush();

            return [
                'ticketId' => $ticketId,
                'estadoAnterior' => $ticket['status'],
                'estadoNuevo' => $nuevoEstado,
                'motivo' => 'window_reopened',
            ];
        });
    }

    /**
     * Resuelve un ticket (manual o automáticamente)
     */
    public function resolver(int $ticketId, string $actor = 'system'): array
    {
        return $this->execute($ticketId, EstadoDeTicket::RESUELTO->value, true, true, $actor);
    }

    /**
     * Cierra un ticket resuelto
     */
    public function cerrar(int $ticketId, string $actor = 'system'): array
    {
        return $this->em->getConnection()->transactional(function() use ($ticketId, $actor) {
            $ticket = $this->ticketRepo->findFilaByIdForUpdate($ticketId);

            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            if ($ticket['status'] !== EstadoDeTicket::RESUELTO->value) {
                throw new \InvalidArgumentException('Only resolved tickets can be closed');
            }

            // Registra la auditoría UNASSIGN si el ticket tiene responsable
            if (!empty($ticket['assigned_agent_id'])) {
                $this->registrarDesasignacionSiEsResponsable($ticketId, $actor);
                $this->em->flush();
            }

            $this->ticketRepo->marcarCerrado($ticketId, EstadoDeTicket::CERRADO->value);

            return [
                'ticketId' => $ticketId,
                'estadoAnterior' => $ticket['status'],
                'estadoNuevo' => EstadoDeTicket::CERRADO->value,
                'previous_assigned_agent_id' => isset($ticket['assigned_agent_id'])
                    ? (int) $ticket['assigned_agent_id'] : null,
                'motivo' => 'success',
            ];
        });
    }
}