/**
 * UIContact — enriched model for UI rendering.
 *
 * Per spec (three-layer model separation):
 * - Used in UI components for display
 * - Contains operational/derived fields beyond entity
 * - Mapped from TransportContactDTO via mappers
 */
export interface UIContact {
  // From DomainContact
  id: string;
  phone: string;
  name: string | null;
  email: string | null;
  // Derived/operational (from DTO, not entity)
  lastInteraction: string | null;
  unreadCount: number;
  activeTicketId: string | null;
  /** Tags — UI placeholder. No backend persistence yet. */
  tags: string[];
  owner: { id: number; name: string } | null;
  status: 'active' | 'inactive';
  channel: 'whatsapp' | 'other';
}

/**
 * Pagination state for contact list.
 */
export interface ContactPagination {
  page: number;
  total: number;
  hasMore: boolean;
  limit: number;
}