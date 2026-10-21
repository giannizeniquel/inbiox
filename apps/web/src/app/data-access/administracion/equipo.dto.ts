/**
 * EquipoDTO — shape returned by `GET /api/equipos` (Tier 2 #7, Slice 1) and
 * `GET/POST/PUT /api/administracion/equipos` (Slice 2).
 *
 * `agent_count`/`agent_ids`/`created_at`/`updated_at` are only present on the
 * admin surface (`Controller/Admin/TeamController::serializeTeam`) — the
 * non-admin `GET /api/equipos` filter-dropdown endpoint returns the minimal
 * `{ id, workspace_id, name, is_active }` shape, so those fields are optional.
 */
export interface EquipoDTO {
  id: number;
  espacioDeTrabajoId: number;
  nombre: string;
  activo: boolean;
  cantidadDeAgentes?: number;
  agenteIds?: number[];
  creadoAt?: string;
  actualizadoAt?: string;
}

/** POST /api/administracion/equipos request body */
export interface CreateEquipoRequest {
  espacioDeTrabajoId: number;
  nombre: string;
  activo?: boolean;
}

/** PUT /api/administracion/equipos/{id} request body — workspaceId is immutable after creation. */
export interface UpdateEquipoRequest {
  nombre?: string;
  activo?: boolean;
}
