import { Injectable, inject } from '@angular/core';
import { Observable, throwError, forkJoin, of } from 'rxjs';
import { map, catchError, switchMap } from 'rxjs/operators';
import { ReporteApiService } from './reporte-api.service';
import { FiltroDeTiempoParams } from './reporte.params';
import { extractApiError } from '../../core/http/extract-api-error';
import {
  CargaDeAgenteEntry,
  SinAsignarResponse,
  AgenteDisponibleEntry,
  TendenciaDeFrtPoint,
  MapaDeCalorCell,
  ContactoRecurrenteEntry,
  ReabiertoEntry,
} from './reporte.models';
import { ReporteDeDemoraDeRespuesta } from './demora-de-respuesta.dto';

/**
 * GetReportsDashboardInput — parameters for fetching the reports dashboard.
 */
export interface GetReportsDashboardInput {
  params: FiltroDeTiempoParams;
}

/**
 * ReportsDashboardData — the full dashboard data combining all 12 API responses.
 */
export interface ReportsDashboardData {
  // ── Group A: KPIs ──
  frt: { value: number };
  rt: { value: number };
  counts: { open: number; closed: number };
  volume: VolumenDataPoint[];
  agents: MetricaDeAgenteData[];
  // ── Group B: Operational ──
  agentLoad: CargaDeAgenteEntry[];
  unassigned: SinAsignarResponse;
  frtTrend: TendenciaDeFrtPoint[];
  responseDelay: ReporteDeDemoraDeRespuesta | null;
  // ── Group C: Detail ──
  availableAgents: AgenteDisponibleEntry[];
  heatmap: MapaDeCalorCell[];
  recurring: ContactoRecurrenteEntry[];
  reopened: ReabiertoEntry[];
}

/**
 * VolumenDataPoint — a single day's volume data.
 */
export interface VolumenDataPoint {
  date: string;
  count: number;
}

/**
 * MetricaDeAgenteData — agent performance metrics for the leaderboard.
 */
export interface MetricaDeAgenteData {
  agentId: string;
  agentName: string;
  assigned: number;
  avgResponseMinutes: number;
  resolved: number;
  loadPercentage: number;
}

/**
 * ReportsFetchFailed — exception thrown when the dashboard fetch fails entirely.
 */
export class ReportsFetchFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'ReportsFetchFailed';
  }
}

/**
 * GetReportsDashboardUseCase — fetches all reports dashboard data.
 *
 * Uses 3 sequential forkJoin groups (4 calls each) for bounded concurrency:
 *   Group A: frt, rt, counts, volume, agents (KPIs)
 *   Group B: agentLoad, unassigned, frtTrend (operational)
 *   Group C: availableAgents, heatmap, recurring, reopened (detail)
 *
 * Each stream has its own catchError so that one failing endpoint
 * doesn't kill the whole dashboard — the failing section gets sensible defaults.
 */
@Injectable({ providedIn: 'root' })
export class GetReportsDashboardUseCase {
  constructor(private readonly api: ReporteApiService = inject(ReporteApiService)) {}

  execute(input: GetReportsDashboardInput): Observable<ReportsDashboardData> {
    // ── Group A: KPIs (frt, rt, counts, volume, agents) ──
    return forkJoin({
      frt: this.api.getFirstResponseTime(input.params).pipe(
        catchError(() => of({ frtPromedioMinutos: 0 })),
      ),
      rt: this.api.getResolutionTime(input.params).pipe(
        catchError(() => of({ rtPromedioMinutos: 0 })),
      ),
      counts: this.api.getConteosDeTickets(input.params).pipe(
        catchError(() => of({ nuevo: 0, activo: 0, en_espera: 0, resuelto: 0, cerrado: 0 })),
      ),
      volume: this.api.getTendenciaDeVolumen(input.params).pipe(
        catchError(() => of({ data: [], total: 0, trend: 0 })),
      ),
      agents: this.api.getMetricasDeAgente(input.params).pipe(
        catchError(() => of({ agents: [] })),
      ),
    }).pipe(
      switchMap((groupA) => {
        // ── Group B: Operational (agentLoad, unassigned, frtTrend) ──
        return forkJoin({
          agentLoad: this.api.getCargaDeAgente().pipe(
            catchError(() => of([] as CargaDeAgenteEntry[])),
          ),
          unassigned: this.api.getSinAsignar(input.params).pipe(
            catchError(() => of({ cantidad: 0, tickets: [] })),
          ),
          frtTrend: this.api.getTendenciaDeFrt(input.params).pipe(
            catchError(() => of([] as TendenciaDeFrtPoint[])),
          ),
          // Degrades to null on failure — NEVER a zeroed object, so the UI
          // can tell "no data yet" apart from "0 seconds average delay".
          responseDelay: this.api.getDemoraDeRespuesta(input.params).pipe(
            catchError(() => of(null as ReporteDeDemoraDeRespuesta | null)),
          ),
        }).pipe(
          switchMap((groupB) => {
            // ── Group C: Detail (availableAgents, heatmap, recurring, reopened) ──
            return forkJoin({
              availableAgents: this.api.getAgentesDisponibles().pipe(
                catchError(() => of([] as AgenteDisponibleEntry[])),
              ),
              heatmap: this.api.getMapaDeCalor(input.params).pipe(
                catchError(() => of([] as MapaDeCalorCell[])),
              ),
              recurring: this.api.getContactosRecurrentes().pipe(
                catchError(() => of([] as ContactoRecurrenteEntry[])),
              ),
              reopened: this.api.getReabiertos(input.params).pipe(
                catchError(() => of([] as ReabiertoEntry[])),
              ),
            }).pipe(
              map((groupC) => ({
                frt: { value: groupA.frt.frtPromedioMinutos || 0 },
                rt: { value: groupA.rt.rtPromedioMinutos || 0 },
                counts: {
                  open: (groupA.counts.nuevo || 0) + (groupA.counts.activo || 0) + (groupA.counts.en_espera || 0),
                  closed: (groupA.counts.resuelto || 0) + (groupA.counts.cerrado || 0),
                },
                volume: (groupA.volume.data || []).map((item) => ({
                  date: item.fecha,
                  count: item.ticketsCreados || 0,
                })),
                agents: (groupA.agents.agents || []).map((agente) => ({
                  agentId: String(agente.agenteId),
                  agentName: `Agent ${agente.agenteId}`,
                  assigned: agente.ticketsAsignados || 0,
                  avgResponseMinutes: agente.frtPromedioMinutos || 0,
                  resolved: agente.ticketsResueltos || 0,
                  loadPercentage: Math.min(100, (agente.ticketsAsignados || 0) * 20),
                })),
                agentLoad: groupB.agentLoad,
                unassigned: groupB.unassigned,
                frtTrend: groupB.frtTrend,
                responseDelay: groupB.responseDelay,
                availableAgents: groupC.availableAgents,
                heatmap: groupC.heatmap,
                recurring: groupC.recurring,
                reopened: groupC.reopened,
              })),
            );
          }),
        );
      }),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to fetch reports dashboard');
        return throwError(() => new ReportsFetchFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createGetReportsDashboardUseCase(
  api?: ReporteApiService,
): GetReportsDashboardUseCase {
  return new GetReportsDashboardUseCase(api);
}
