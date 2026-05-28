/**
 * DomainContact — canonical model for domain logic.
 *
 * Per spec (three-layer model separation):
 * - Used in domain logic (use cases, business rules)
 * - Contains only entity-pure fields
 * - NOT enriched with operational/derived data
 */
export interface DomainContact {
  id: string;
  phone: string;
  name: string | null;
  email: string | null;
  createdAt: string;
  updatedAt: string;
}