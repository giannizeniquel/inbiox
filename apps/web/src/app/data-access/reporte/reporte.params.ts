/**
 * Shared types for Reports feature — time filter params.
 */

export interface FiltroDeTiempoParams {
  fromDate?: string;
  toDate?: string;
  /**
   * Single-select team filter (team-area-entity, Slice 3). Only forwarded
   * by the backend on `frt`, `rt`, `counts`, `volume`, and `agents` — the 5
   * ticket-scoped reporting methods. Harmless no-op on other endpoints.
   */
  teamId?: number;
}

export type FiltroDeTiempo = 'today' | '7d' | '30d' | 'custom';

// ── Reporting v2 param interfaces ──

/** Agent load report params */
export interface CargaDeAgenteParams {
  agentId?: number;
}

/** Unassigned tickets params */
export interface SinAsignarParams extends FiltroDeTiempoParams {
  phoneNumberId?: string;
}

/** Available agents params */
export interface AgentesDisponiblesParams {
  phoneNumberId?: string;
}

/** FRT trend params */
export interface TendenciaDeFrtParams extends FiltroDeTiempoParams {}

/** Heatmap params */
export interface MapaDeCalorParams extends FiltroDeTiempoParams {}

/** Recurring customers params */
export interface ContactosRecurrentesParams {
  phoneNumberId?: string;
  threshold?: number;
}

/** Reopened tickets params */
export interface ReabiertosParams extends FiltroDeTiempoParams {}
