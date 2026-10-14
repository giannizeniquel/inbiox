/**
 * Model Mappers — pure functions for layer transitions.
 *
 * Per spec: mapping functions are pure, testable in isolation,
 * and live at the feature boundary (models/mappers.ts).
 *
 * Components receive UIContact, never raw DTOs.
 * Use cases handle DTO→UIContact mapping.
 */

import { DomainContact } from './contact.model';
import { UIContact } from './contact-ui.model';
import {
  ContactListItemDTO,
  ContactDetailDTO,
} from './contact-transport.dto';

/**
 * Map ContactListItemDTO (API list) → UIContact
 */
export function mapListItemToUIContact(dto: ContactListItemDTO): UIContact {
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
 * Map ContactDetailDTO (API detail) → UIContact
 */
export function mapDetailToUIContact(dto: ContactDetailDTO): UIContact {
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
 * Map TransportContactDTO → DomainContact (canonical entity fields only)
 */
export function mapDTOToDomainContact(dto: ContactListItemDTO | ContactDetailDTO): DomainContact {
  return {
    id: dto.id,
    phone: dto.phone,
    name: dto.name,
    email: dto.email,
    createdAt: 'createdAt' in dto ? (dto as ContactDetailDTO).createdAt : new Date().toISOString(),
    updatedAt: dto.updatedAt,
  };
}

/**
 * Map UIContact → DomainContact (drop operational fields)
 */
export function mapUIContactToDomain(contact: UIContact): DomainContact {
  return {
    id: contact.id,
    phone: contact.phone,
    name: contact.name,
    email: contact.email,
    createdAt: new Date().toISOString(), // Not in UIContact — use current
    updatedAt: new Date().toISOString(),
  };
}

/**
 * Batch map for list operations.
 */
export function mapContactListToUI(
  items: ContactListItemDTO[]
): UIContact[] {
  return items.map(mapListItemToUIContact);
}