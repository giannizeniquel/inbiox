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
        catchError(() => of({ avg_frt_minutes: 0 })),
      ),
      rt: this.api.getResolutionTime(input.params).pipe(
        catchError(() => of({ avg_rt_minutes: 0 })),
      ),
      counts: this.api.getTicketCounts(input.params).pipe(
        catchError(() => of({ new: 0, active: 0, waiting: 0, resolved: 0, closed: 0 })),
      ),
      volume: this.api.getVolumeTrend(input.params).pipe(
        catchError(() => of({ data: [], total: 0, trend: 0 })),
      ),
      agents: this.api.getAgentMetrics(input.params).pipe(
        catchError(() => of({ agents: [] })),
      ),
    }).pipe(
      switchMap((groupA) => {
        // ── Group B: Operational (agentLoad, unassigned, frtTrend) ──
        return forkJoin({
          agentLoad: this.api.getAgentLoad().pipe(
            catchError(() => of([] as CargaDeAgenteEntry[])),
          ),
          unassigned: this.api.getUnassigned(input.params).pipe(
            catchError(() => of({ count: 0, tickets: [] })),
          ),
          frtTrend: this.api.getFrtTrend(input.params).pipe(
            catchError(() => of([] as TendenciaDeFrtPoint[])),
          ),
          // Degrades to null on failure — NEVER a zeroed object, so the UI
          // can tell "no data yet" apart from "0 seconds average delay".
          responseDelay: this.api.getResponseDelay(input.params).pipe(
            catchError(() => of(null as ReporteDeDemoraDeRespuesta | null)),
          ),
        }).pipe(
          switchMap((groupB) => {
            // ── Group C: Detail (availableAgents, heatmap, recurring, reopened) ──
            return forkJoin({
              availableAgents: this.api.getAvailableAgents().pipe(
                catchError(() => of([] as AgenteDisponibleEntry[])),
              ),
              heatmap: this.api.getHeatmap(input.params).pipe(
                catchError(() => of([] as MapaDeCalorCell[])),
              ),
              recurring: this.api.getRecurringCustomers().pipe(
                catchError(() => of([] as ContactoRecurrenteEntry[])),
              ),
              reopened: this.api.getReopened(input.params).pipe(
                catchError(() => of([] as ReabiertoEntry[])),
              ),
            }).pipe(
              map((groupC) => ({
                frt: { value: groupA.frt.avg_frt_minutes || 0 },
                rt: { value: groupA.rt.avg_rt_minutes || 0 },
                counts: {
                  open: (groupA.counts.new || 0) + (groupA.counts.active || 0) + (groupA.counts.waiting || 0),
                  closed: (groupA.counts.resolved || 0) + (groupA.counts.closed || 0),
                },
                volume: (groupA.volume.data || []).map((item) => ({
                  date: item.date,
                  count: item.tickets_created || 0,
                })),
                agents: (groupA.agents.agents || []).map((agent) => ({
                  agentId: String(agent.agent_id),
                  agentName: `Agent ${agent.agent_id}`,
                  assigned: agent.tickets_assigned || 0,
                  avgResponseMinutes: agent.avg_frt_minutes || 0,
                  resolved: agent.tickets_resolved || 0,
                  loadPercentage: Math.min(100, (agent.tickets_assigned || 0) * 20),
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
