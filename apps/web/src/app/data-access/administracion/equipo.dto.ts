/**
 * EquipoDTO — shape returned by `GET /api/teams` (Tier 2 #7, Slice 1) and
 * `GET/POST/PUT /api/admin/teams` (Slice 2).
 *
 * `agent_count`/`agent_ids`/`created_at`/`updated_at` are only present on the
 * admin surface (`Controller/Admin/TeamController::serializeTeam`) — the
 * non-admin `GET /api/teams` filter-dropdown endpoint returns the minimal
 * `{ id, workspace_id, name, is_active }` shape, so those fields are optional.
 */
export interface EquipoDTO {
  id: number;
  workspace_id: number;
  name: string;
  is_active: boolean;
  agent_count?: number;
  agent_ids?: number[];
  created_at?: string;
  updated_at?: string;
}

/** POST /api/admin/teams request body */
export interface CreateEquipoRequest {
  workspaceId: number;
  name: string;
  isActive?: boolean;
}

/** PUT /api/admin/teams/{id} request body — workspaceId is immutable after creation. */
export interface UpdateEquipoRequest {
  name?: string;
  isActive?: boolean;
}
