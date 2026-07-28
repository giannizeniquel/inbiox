import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { TimeFilterParams } from '../dto/reports.params';

/** Backend response for GET /api/reports/frt */
export interface FirstResponseTimeResponse {
  avg_frt_minutes: number;
}

/** Backend response for GET /api/reports/rt */
export interface ResolutionTimeResponse {
  avg_rt_minutes: number;
}

/** Backend response for GET /api/reports/counts */
export interface TicketCountsResponse {
  new: number;
  active: number;
  waiting: number;
  resolved: number;
  closed: number;
}

/** Backend response for GET /api/reports/volume — array of daily data */
export interface VolumeDataPoint {
  date: string;
  tickets_created: number;
}

export interface VolumeTrendResponse {
  data: VolumeDataPoint[];
  total: number;
  trend: number;
}

/** Backend response for GET /api/reports/agents */
export interface AgentMetricsData {
  agent_id: number;
  tickets_assigned: number;
  avg_frt_minutes: number;
  tickets_resolved: number;
}

export interface AgentMetricsResponse {
  agents: AgentMetricsData[];
}

/**
 * ReportsApiService — HTTP client for reports endpoints.
 */
@Injectable({ providedIn: 'root' })
export class ReportsApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  private buildParams(params?: TimeFilterParams): HttpParams {
    let httpParams = new HttpParams();

    if (params?.fromDate) {
      const from = params.fromDate.length === 10
        ? `${params.fromDate}T00:00:00Z`
        : params.fromDate;
      httpParams = httpParams.set('from', from);
    }

    if (params?.toDate) {
      const to = params.toDate.length === 10
        ? `${params.toDate}T23:59:59Z`
        : params.toDate;
      httpParams = httpParams.set('to', to);
    }

    return httpParams;
  }

  getFirstResponseTime(params?: TimeFilterParams): Observable<FirstResponseTimeResponse> {
    return this.http.get<FirstResponseTimeResponse>(`${this.baseUrl}/api/reports/frt`, {
      params: this.buildParams(params),
    });
  }

  getResolutionTime(params?: TimeFilterParams): Observable<ResolutionTimeResponse> {
    return this.http.get<ResolutionTimeResponse>(`${this.baseUrl}/api/reports/rt`, {
      params: this.buildParams(params),
    });
  }

  getTicketCounts(params?: TimeFilterParams): Observable<TicketCountsResponse> {
    return this.http.get<TicketCountsResponse>(`${this.baseUrl}/api/reports/counts`, {
      params: this.buildParams(params),
    });
  }

  getVolumeTrend(params?: TimeFilterParams): Observable<VolumeTrendResponse> {
    return this.http.get<VolumeTrendResponse>(`${this.baseUrl}/api/reports/volume`, {
      params: this.buildParams(params),
    });
  }

  getAgentMetrics(params?: TimeFilterParams): Observable<AgentMetricsResponse> {
    return this.http.get<AgentMetricsResponse>(`${this.baseUrl}/api/reports/agents`, {
      params: this.buildParams(params),
    });
  }
}
