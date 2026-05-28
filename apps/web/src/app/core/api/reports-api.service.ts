import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { TimeFilterParams } from '../../features/reports/models/reports.models';

export interface FirstResponseTimeResponse {
  avgMinutes: number;
  trend: number;
}

export interface ResolutionTimeResponse {
  avgMinutes: number;
  trend: number;
}

export interface TicketCountsResponse {
  open: number;
  closed: number;
  pending: number;
}

export interface VolumeData {
  date: string;
  count: number;
}

export interface VolumeTrendResponse {
  data: VolumeData[];
  total: number;
  trend: number;
}

export interface AgentMetrics {
  agentId: string;
  agentName: string;
  assigned: number;
  avgResponseMinutes: number;
  resolved: number;
  loadPercentage: number;
}

export interface AgentMetricsResponse {
  agents: AgentMetrics[];
}

/**
 * ReportsApiService — HTTP client for reports endpoints.
 *
 * Calls Symfony backend at:
 * - GET /reports/frt (First Response Time)
 * - GET /reports/rt (Resolution Time)
 * - GET /reports/counts (Ticket Counts)
 * - GET /reports/volume (Volume Trend)
 * - GET /reports/agents (Agent Metrics)
 */
@Injectable({ providedIn: 'root' })
export class ReportsApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /**
   * Build HTTP params in the format the backend expects.
   * Backend expects 'from' and 'to' in ISO datetime format: Y-m-d\TH:i:sZ
   */
  private buildParams(params?: TimeFilterParams): HttpParams {
    let httpParams = new HttpParams();
    
    if (params?.fromDate) {
      // Convert YYYY-MM-DD to YYYY-MM-DDTHH:MM:SSZ
      const from = params.fromDate.length === 10 
        ? `${params.fromDate}T00:00:00Z`
        : params.fromDate;
      httpParams = httpParams.set('from', from);
    }
    
    if (params?.toDate) {
      // Convert YYYY-MM-DD to YYYY-MM-DDTHH:MM:SSZ (end of day)
      const to = params.toDate.length === 10 
        ? `${params.toDate}T23:59:59Z`
        : params.toDate;
      httpParams = httpParams.set('to', to);
    }
    
    return httpParams;
  }

  /**
   * GET /reports/frt - First Response Time
   */
  getFirstResponseTime(params?: TimeFilterParams): Observable<FirstResponseTimeResponse> {
    return this.http.get<FirstResponseTimeResponse>(`${this.baseUrl}/reports/frt`, {
      params: this.buildParams(params),
    });
  }

  /**
   * GET /reports/rt - Resolution Time
   */
  getResolutionTime(params?: TimeFilterParams): Observable<ResolutionTimeResponse> {
    return this.http.get<ResolutionTimeResponse>(`${this.baseUrl}/reports/rt`, {
      params: this.buildParams(params),
    });
  }

  /**
   * GET /reports/counts - Ticket Counts
   */
  getTicketCounts(params?: TimeFilterParams): Observable<TicketCountsResponse> {
    return this.http.get<TicketCountsResponse>(`${this.baseUrl}/reports/counts`, {
      params: this.buildParams(params),
    });
  }

  /**
   * GET /reports/volume - Volume Trend
   */
  getVolumeTrend(params?: TimeFilterParams): Observable<VolumeTrendResponse> {
    return this.http.get<VolumeTrendResponse>(`${this.baseUrl}/reports/volume`, {
      params: this.buildParams(params),
    });
  }

  /**
   * GET /reports/agents - Agent Metrics
   */
  getAgentMetrics(params?: TimeFilterParams): Observable<AgentMetricsResponse> {
    return this.http.get<AgentMetricsResponse>(`${this.baseUrl}/reports/agents`, {
      params: this.buildParams(params),
    });
  }
}