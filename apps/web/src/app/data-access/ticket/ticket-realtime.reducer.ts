import type { TicketStore } from './ticket.store';
import type { EspacioDeTrabajoStore } from '../administracion/espacio-de-trabajo.store';
import { EstadoDeTicket } from './ticket.model';
import { EventoEnTiempoReal, TipoDeEventoEnTiempoReal,
  MensajeAgregadoPayload, TicketCreadoPayload, EstadoDeTicketCambiadoPayload,
  TicketAsignadoPayload } from '../../core/tiempo-real/evento-en-tiempo-real.model';

/** Apply one ticket-related realtime event (from the EventBus) to the ticket list and the workspace pending flags. */
export function applyTicketEvent(event: EventoEnTiempoReal, store: TicketStore, espacioDeTrabajoStore: EspacioDeTrabajoStore): void {
  switch (event.tipoDeEvento) {
    case TipoDeEventoEnTiempoReal.MensajeAgregado: {
      const p = event.payload as MensajeAgregadoPayload;
      // Increment unread if not active ticket — inbound only (message-agent-
      // attribution, design.md D6): outbound messages now broadcast too
      // (human-agent replies), and an agent's own reply (or a colleague's)
      // must never inflate the unread badge.
      const activeTicket = store.activeTicket();
      if (
        p.direccion === 'entrante'
        && (!activeTicket || String(activeTicket.ticketId) !== String(p.ticketId))
      ) {
        const current = store.tickets().find(t => String(t.ticketId) === String(p.ticketId));
        if (current) {
          store.updateConteoDeNoLeidos(String(p.ticketId), (current.metricas?.cantidadDeNoLeidos ?? 0) + 1);
        }
      }
      // Workspace pending indicator: if inbound message from a different workspace
      if (p.direccion === 'entrante' && p.phoneNumberId) {
        const activePhone = espacioDeTrabajoStore.activePhoneNumberId();
        if (activePhone && activePhone !== p.phoneNumberId) {
          const ws = espacioDeTrabajoStore.espaciosDeTrabajo().find(
            w => w.phone_number_id === p.phoneNumberId
          );
          if (ws) {
            espacioDeTrabajoStore.setPending(ws.id, true);
          }
        }
      }
      break;
    }

    case TipoDeEventoEnTiempoReal.TicketCreado: {
      const p = event.payload as TicketCreadoPayload;
      store.addOrUpdateTicket({
        ticketId: String(p.ticketId),
        id: String(p.ticketId),
        estado: p.estado as EstadoDeTicket,
        prioridad: String(p.prioridad),
        contacto: null,
        ultimoMensaje: null,
        responsable: null,
        metricas: { cantidadDeNoLeidos: 0 },
        fechas: { abiertoAt: p.creadoAt, actualizadoAt: p.creadoAt },
      });
      break;
    }

    case TipoDeEventoEnTiempoReal.EstadoDeTicketCambiado: {
      const p = event.payload as EstadoDeTicketCambiadoPayload;
      const existing = store.tickets().find(t => String(t.ticketId) === String(p.ticketId));
      if (existing) {
        store.addOrUpdateTicket({
          ...existing,
          estado: p.estadoNuevo as EstadoDeTicket,
          fechas: { ...existing.fechas, actualizadoAt: p.cambiadoAt },
        });
      }
      break;
    }

    case TipoDeEventoEnTiempoReal.TicketAsignado: {
      const p = event.payload as TicketAsignadoPayload;
      const existing = store.tickets().find(t => String(t.ticketId) === String(p.ticketId));
      if (existing) {
        // Only overwrite owner name when Mercure provides a real name.
        // Otherwise keep the name from optimistic update (or existing owner).
        const nombreDeResponsable = p.responsableNombre ?? existing.responsable?.nombre ?? `Agent #${p.responsableId}`;
        // Implicit status transition: unassigned (new) → active on assignment
        const estabaSinAsignar = existing.estado === EstadoDeTicket.Nuevo || existing.responsable === null;
        store.addOrUpdateTicket({
          ...existing,
          responsable: { id: p.responsableId, nombre: nombreDeResponsable },
          estado: estabaSinAsignar ? EstadoDeTicket.Activo : existing.estado,
        });
      }
      // When a ticket is reassigned, clear the unread badge for the new owner
      store.marcarComoLeido(String(event.ticketId));
      break;
    }

    case TipoDeEventoEnTiempoReal.TicketLeido: {
      // Another user marked this ticket as read — clear our badge
      const ticketId = event.ticketId ? String(event.ticketId) : null;
      if (ticketId) {
        store.marcarComoLeido(ticketId);
      }
      break;
    }
  }
}
