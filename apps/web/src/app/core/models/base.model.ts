/**
 * BaseModel — shared interface for all domain entities.
 * All timestamps are ISO 8601 strings.
 */
export interface BaseModel {
  id: string;
  createdAt: string;
  updatedAt?: string;
}
