/**
 * Model Mappers — pure functions for layer transitions.
 *
 * Per spec: mapping functions are pure, testable in isolation,
 * and live at the feature boundary (models/mappers.ts).
 *
 * Components receive ContactoUI, never raw DTOs.
 * Use cases handle DTO→ContactoUI mapping.
 */

import { ContactoDominio } from './contacto.model';
import { ContactoUI } from './contacto-ui.model';
import {
  ContactoDeListaDTO,
  DetalleDeContactoDTO,
} from './contacto-transport.dto';

/**
 * Map ContactoDeListaDTO (API list) → ContactoUI
 */
export function mapearItemDeListaAContactoUI(dto: ContactoDeListaDTO): ContactoUI {
  return {
    id: dto.id,
    phone: dto.phone,
    name: dto.name,
    email: dto.email,
    lastInteraction: dto.lastInteraction,
    unreadCount: dto.unreadCount,
    activeTicketId: dto.activeTicketId,
    tags: dto.tags ?? [],
    owner: (dto.ownerId !== null && dto.ownerName !== null)
      ? { id: dto.ownerId, name: dto.ownerName }
      : null,
    status: dto.status,
    channel: dto.channel,
  };
}

/**
 * Map DetalleDeContactoDTO (API detail) → ContactoUI
 */
export function mapearDetalleAContactoUI(dto: DetalleDeContactoDTO): ContactoUI {
  return {
    id: dto.id,
    phone: dto.phone,
    name: dto.name,
    email: dto.email ?? null, // Available in detail endpoint
    lastInteraction: dto.lastInteraction,
    unreadCount: dto.unreadCount,
    activeTicketId: dto.activeTicketId,
    tags: dto.tags ?? [],
    owner: (dto.ownerId !== null && dto.ownerName !== null)
      ? { id: dto.ownerId, name: dto.ownerName }
      : null,
    status: dto.status,
    channel: dto.channel,
  };
}

/**
 * Map TransportContactDTO → ContactoDominio (canonical entity fields only)
 */
export function mapearDTOAContactoDominio(dto: ContactoDeListaDTO | DetalleDeContactoDTO): ContactoDominio {
  return {
    id: dto.id,
    phone: dto.phone,
    name: dto.name,
    email: dto.email,
    createdAt: 'createdAt' in dto ? (dto as DetalleDeContactoDTO).createdAt : new Date().toISOString(),
    updatedAt: dto.updatedAt,
  };
}

/**
 * Map ContactoUI → ContactoDominio (drop operational fields)
 */
export function mapUIContactToDomain(contacto: ContactoUI): ContactoDominio {
  return {
    id: contacto.id,
    phone: contacto.phone,
    name: contacto.name,
    email: contacto.email,
    createdAt: new Date().toISOString(), // Not in ContactoUI — use current
    updatedAt: new Date().toISOString(),
  };
}

/**
 * Batch map for list operations.
 */
export function mapearListaDeContactosAUI(
  items: ContactoDeListaDTO[]
): ContactoUI[] {
  return items.map(mapearItemDeListaAContactoUI);
}