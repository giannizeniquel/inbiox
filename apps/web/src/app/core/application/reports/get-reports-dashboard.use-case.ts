import { Injectable, inject } from '@angular/core';
import { Observable, throwError, forkJoin, of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ReportsApiService } from '../../api/reports-api.service';
import { TimeFilterParams } from '../../dto/reports.params';
import { extractApiError } from '../shared/extract-api-error';

/**
 * GetReportsDashboardInput — parameters for fetching the reports dashboard.
 */
export interface GetReportsDashboardInput {
  params: TimeFilterParams;
}

/**
 * ReportsDashboardData — the full dashboard data combining all 5 API responses.
 */
export interface ReportsDashboardData {
  /** First Response Time in minutes */
  frt: { value: number };
  /** Resolution Time in minutes */
  rt: { value: number };
  /** Ticket counts (open = new + active + waiting, closed = resolved + closed) */
  counts: { open: number; closed: number };
  /** Volume trend data points */
  volume: VolumeDataPoint[];
  /** Agent leaderboard metrics */
  agents: AgentMetricData[];
}

/**
 * VolumeDataPoint — a single day's volume data.
 */
export interface VolumeDataPoint {
  date: string;
  count: number;
}

/**
 * AgentMetricData — agent performance metrics for the leaderboard.
 */
export interface AgentMetricData {
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
 * Uses forkJoin over 5 concurrent API calls: FRT, RT, ticket counts,
 * volume trend, and agent metrics. Each stream has its own catchError
 * so that one failing endpoint doesn't kill the whole dashboard —
 * the failing section gets sensible defaults.
 *
 * Maps raw API responses into the ReportsDashboardData shape.
 */
@Injectable({ providedIn: 'root' })
export class GetReportsDashboardUseCase {
  constructor(private readonly api: ReportsApiService = inject(ReportsApiService)) {}

  execute(input: GetReportsDashboardInput): Observable<ReportsDashboardData> {
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
      map(({ frt, rt, counts, volume, agents }) => ({
        frt: { value: frt.avg_frt_minutes || 0 },
        rt: { value: rt.avg_rt_minutes || 0 },
        counts: {
          open: (counts.new || 0) + (counts.active || 0) + (counts.waiting || 0),
          closed: (counts.resolved || 0) + (counts.closed || 0),
        },
        volume: (volume.data || []).map((item) => ({
          date: item.date,
          count: item.tickets_created || 0,
        })),
        agents: (agents.agents || []).map((agent) => ({
          agentId: String(agent.agent_id),
          agentName: `Agent ${agent.agent_id}`,
          assigned: agent.tickets_assigned || 0,
          avgResponseMinutes: agent.avg_frt_minutes || 0,
          resolved: agent.tickets_resolved || 0,
          loadPercentage: Math.min(100, (agent.tickets_assigned || 0) * 20),
        })),
      })),
      catchError((err) => {
        // This catches unexpected errors (e.g., forkJoin structural failure)
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
  api?: ReportsApiService,
): GetReportsDashboardUseCase {
  return new GetReportsDashboardUseCase(api);
}
