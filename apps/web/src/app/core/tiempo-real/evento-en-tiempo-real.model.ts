/**
 * RealtimeEventContract v1 — TypeScript mirror of the PHP backend contract.
 *
 * This enum and interfaces are a 1:1 mapping of the PHP TipoDeEventoEnTiempoReal
 * (backed string enum) and the wire JSON shape produced by RealtimeEventFactory.
 *
 * No field transformations, no adapters, no `as any` casts — the frontend
 * matches the backend wire format exactly.
 */

// ──────────────────────────────────────────────
// Enum — must match PHP App\Compartido\Realtime\TipoDeEventoEnTiempoReal exactly
// ──────────────────────────────────────────────

export enum TipoDeEventoEnTiempoReal {
  TicketCreado = 'ticket.creado',
  EstadoDeTicketCambiado = 'ticket.estado_cambiado',
  TicketAsignado = 'ticket.asignado',
  MensajeAgregado = 'mensaje.agregado',
  MensajeActualizado = 'mensaje.actualizado',
  TicketLeido = 'ticket.leido',
}

// ──────────────────────────────────────────────
// Generic EventoEnTiempoReal interface — matches wire JSON shape
// ──────────────────────────────────────────────

export interface EventoEnTiempoReal<T = unknown> {
  eventoId: string;
  sequence: number;
  ocurridoAt: string;
  tipoDeEvento: TipoDeEventoEnTiempoReal;
  ticketId?: string;
  payload: T;
}

// ──────────────────────────────────────────────
// Typed payload interfaces per event type
// ──────────────────────────────────────────────

/** Payload for `mensaje.agregado` events */
export interface MensajeAgregadoPayload {
  mensajeId: number;
  ticketId: number;
  contenido: string;
  direccion: 'entrante' | 'saliente';
  remitenteNombre: string | null;
  creadoAt: string;
  externalId: string | null;
  phoneNumberId?: string | null;
  adjunto?: {
    key: string;
    mimeType: string;
    nombreDeArchivo: string;
    tamanio: number;
    tipo: 'imagen' | 'video' | 'audio' | 'documento';
    providerId?: string;
    leyenda?: string;
  } | null;
}

/** Payload for `ticket.creado` events */
export interface TicketCreadoPayload {
  ticketId: number;
  contactoId: number;
  conversacionId: number;
  estado: string;
  prioridad: string;
  creadoAt: string;
}

/** Payload for `ticket.estado_cambiado` events */
export interface EstadoDeTicketCambiadoPayload {
  ticketId: number;
  estadoAnterior: string | null;
  estadoNuevo: string;
  cambiadoAt: string;
}

/** Payload for `ticket.asignado` events */
export interface TicketAsignadoPayload {
  ticketId: number;
  responsableId: number;
  responsableNombre?: string | null;
  asignadoAt: string;
}

/** Payload for `mensaje.actualizado` events */
export interface MensajeActualizadoPayload {
  mensajeId: number;
  ticketId: number;
  estadoDeEntrega: string | null;
  motivoDeFallo: string | null;
  actualizadoAt: string;
}

// ──────────────────────────────────────────────
// Utility: compile-time exhaustiveness check
// ──────────────────────────────────────────────

/**
 * Assert never — use as the `default` branch in a strict switch over
 * TipoDeEventoEnTiempoReal. If a new value is added to the enum but not handled,
 * TypeScript produces a compile-time error.
 *
 * Usage:
 *   switch (event.tipoDeEvento) {
 *     case TipoDeEventoEnTiempoReal.MensajeAgregado: ... break;
 *     default: assertNever(event.tipoDeEvento);
 *   }
 */
export function assertNever(value: never): never {
  throw new Error(`Unhandled TipoDeEventoEnTiempoReal case: ${value}`);
}
