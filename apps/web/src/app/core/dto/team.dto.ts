/**
 * TeamDTO — shape returned by `GET /api/teams` (Tier 2 #7, Slice 1).
 *
 * Minimal read-only shape for the inbox/reports team filter dropdown
 * (Slice 3). The admin CRUD surface (Slice 2) will extend this file with
 * create/update/delete request shapes when it lands — kept intentionally
 * small here to avoid guessing that shape ahead of time.
 */
export interface TeamDTO {
  id: number;
  workspace_id: number;
  name: string;
  is_active: boolean;
}
