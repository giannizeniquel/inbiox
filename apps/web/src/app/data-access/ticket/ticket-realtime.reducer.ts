import type { TicketStore } from './ticket.store';
import type { EspacioDeTrabajoStore } from '../administracion/espacio-de-trabajo.store';
import { EstadoDeTicket } from './ticket.model';
import { EventoEnTiempoReal, TipoDeEventoEnTiempoReal,
  MessageAddedPayload, TicketCreatedPayload, TicketStatusChangedPayload,
  TicketAssignedPayload } from '../../core/tiempo-real/evento-en-tiempo-real.model';

/** Apply one ticket-related realtime event (from the EventBus) to the ticket list and the workspace pending flags. */
export function applyTicketEvent(event: EventoEnTiempoReal, store: TicketStore, workspaceStore: EspacioDeTrabajoStore): void {
  switch (event.eventType) {
    case TipoDeEventoEnTiempoReal.MessageAdded: {
      const p = event.payload as MessageAddedPayload;
      // Increment unread if not active ticket — inbound only (message-agent-
      // attribution, design.md D6): outbound messages now broadcast too
      // (human-agent replies), and an agent's own reply (or a colleague's)
      // must never inflate the unread badge.
      const activeTicket = store.activeTicket();
      if (
        p.direction === 'inbound'
        && (!activeTicket || String(activeTicket.ticket_id) !== String(p.ticketId))
      ) {
        const current = store.tickets().find(t => String(t.ticket_id) === String(p.ticketId));
        if (current) {
          store.updateUnreadCount(String(p.ticketId), (current.metrics?.unread_count ?? 0) + 1);
        }
      }
      // Workspace pending indicator: if inbound message from a different workspace
      if (p.direction === 'inbound' && p.phoneNumberId) {
        const activePhone = workspaceStore.activePhoneNumberId();
        if (activePhone && activePhone !== p.phoneNumberId) {
          const ws = workspaceStore.workspaces().find(
            w => w.phone_number_id === p.phoneNumberId
          );
          if (ws) {
            workspaceStore.setPending(ws.id, true);
          }
        }
      }
      break;
    }

    case TipoDeEventoEnTiempoReal.TicketCreated: {
      const p = event.payload as TicketCreatedPayload;
      store.addOrUpdateTicket({
        ticket_id: String(p.ticketId),
        id: String(p.ticketId),
        status: p.status as EstadoDeTicket,
        priority: String(p.priority),
        contact: null,
        last_message: null,
        owner: null,
        metrics: { unread_count: 0 },
        timestamps: { opened_at: p.createdAt, updated_at: p.createdAt },
      });
      break;
    }

    case TipoDeEventoEnTiempoReal.TicketStatusChanged: {
      const p = event.payload as TicketStatusChangedPayload;
      const existing = store.tickets().find(t => String(t.ticket_id) === String(p.ticketId));
      if (existing) {
        store.addOrUpdateTicket({
          ...existing,
          status: p.newStatus as EstadoDeTicket,
          timestamps: { ...existing.timestamps, updated_at: p.changedAt },
        });
      }
      break;
    }

    case TipoDeEventoEnTiempoReal.TicketAssigned: {
      const p = event.payload as TicketAssignedPayload;
      const existing = store.tickets().find(t => String(t.ticket_id) === String(p.ticketId));
      if (existing) {
        // Only overwrite owner name when Mercure provides a real name.
        // Otherwise keep the name from optimistic update (or existing owner).
        const ownerName = p.assigneeName ?? existing.owner?.name ?? `Agent #${p.assigneeId}`;
        // Implicit status transition: unassigned (new) → active on assignment
        const wasUnassigned = existing.status === EstadoDeTicket.Nuevo || existing.owner === null;
        store.addOrUpdateTicket({
          ...existing,
          owner: { id: p.assigneeId, name: ownerName },
          status: wasUnassigned ? EstadoDeTicket.Activo : existing.status,
        });
      }
      // When a ticket is reassigned, clear the unread badge for the new owner
      store.markAsRead(String(event.ticketId));
      break;
    }

    case TipoDeEventoEnTiempoReal.TicketRead: {
      // Another user marked this ticket as read — clear our badge
      const ticketId = event.ticketId ? String(event.ticketId) : null;
      if (ticketId) {
        store.markAsRead(ticketId);
      }
      break;
    }
  }
}
