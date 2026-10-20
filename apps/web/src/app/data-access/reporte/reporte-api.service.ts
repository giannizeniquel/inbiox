import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import {
  FiltroDeTiempoParams,
  CargaDeAgenteParams,
  SinAsignarParams,
  TendenciaDeFrtParams,
  MapaDeCalorParams,
  ContactosRecurrentesParams,
} from './reporte.params';
import { ReporteDeDemoraDeRespuesta, ParticipacionDeTicket } from './demora-de-respuesta.dto';
import { extractApiError } from '../../core/http/extract-api-error';

/** Backend response for GET /api/reports/frt */
export interface FirstResponseTimeResponse {
  avg_frt_minutes: number;
}

/** Backend response for GET /api/reports/rt */
export interface ResolutionTimeResponse {
  avg_rt_minutes: number;
}

/** Backend response for GET /api/reports/counts */
export interface ConteosDeTicketsResponse {
  new: number;
  active: number;
  waiting: number;
  resolved: number;
  closed: number;
}

/** Backend response for GET /api/reports/volume — array of daily data */
export interface VolumenDataPoint {
  date: string;
  tickets_created: number;
}

export interface TendenciaDeVolumenResponse {
  data: VolumenDataPoint[];
  total: number;
  trend: number;
}

/** Backend response for GET /api/reports/agents */
export interface MetricasDeAgenteData {
  agent_id: number;
  tickets_assigned: number;
  avg_frt_minutes: number;
  tickets_resolved: number;
}

export interface MetricasDeAgenteResponse {
  agents: MetricasDeAgenteData[];
}

// ── Reporting v2 response interfaces ──

/** Backend response for GET /api/reports/agent-load */
export interface CargaDeAgenteEntry {
  agent_id: number;
  agent_name: string;
  active_tickets: number;
  max_capacity: number;
  load_percent: number;
}

/** Backend response for GET /api/reports/unassigned */
export interface SinAsignarResponse {
  count: number;
  tickets: Array<{
    id: number;
    contact_name: string | null;
    created_at: string;
    priority: string;
  }>;
}

/** Backend response for GET /api/reports/available-agents */
export interface AgenteDisponibleEntry {
  agent_id: number;
  agent_name: string;
  in_shift: boolean;
  active_tickets: number;
}

/** Backend response for GET /api/reports/frt-trend */
export interface TendenciaDeFrtPoint {
  date: string;
  avg_frt_seconds: number;
  ticket_count: number;
}

/** Backend response for GET /api/reports/heatmap */
export interface MapaDeCalorCell {
  day_of_week: number;
  hour: number;
  count: number;
}

/** Backend response for GET /api/reports/recurring-customers */
export interface ContactoRecurrenteEntry {
  contact_id: number;
  contact_name: string | null;
  email: string | null;
  ticket_count: number;
  last_ticket_at: string;
}

/** Backend response for GET /api/reports/reopened */
export interface ReabiertoEntry {
  ticket_id: number;
  contact_name: string | null;
  resolved_at: string | null;
  reopened_at: string;
  current_status: string;
}

/**
 * ReporteApiService — HTTP client for reports endpoints.
 */
@Injectable({ providedIn: 'root' })
export class ReporteApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  private buildParams(params?: FiltroDeTiempoParams): HttpParams {
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

    if (params?.teamId !== undefined && params.teamId !== null) {
      httpParams = httpParams.set('teamId', String(params.teamId));
    }

    return httpParams;
  }

  // ── Existing methods ──

  getFirstResponseTime(params?: FiltroDeTiempoParams): Observable<FirstResponseTimeResponse> {
    return this.http.get<FirstResponseTimeResponse>(`${this.baseUrl}/api/reports/frt`, {
      params: this.buildParams(params),
    });
  }

  getResolutionTime(params?: FiltroDeTiempoParams): Observable<ResolutionTimeResponse> {
    return this.http.get<ResolutionTimeResponse>(`${this.baseUrl}/api/reports/rt`, {
      params: this.buildParams(params),
    });
  }

  getTicketCounts(params?: FiltroDeTiempoParams): Observable<ConteosDeTicketsResponse> {
    return this.http.get<ConteosDeTicketsResponse>(`${this.baseUrl}/api/reports/counts`, {
      params: this.buildParams(params),
    });
  }

  getVolumeTrend(params?: FiltroDeTiempoParams): Observable<TendenciaDeVolumenResponse> {
    return this.http.get<TendenciaDeVolumenResponse>(`${this.baseUrl}/api/reports/volume`, {
      params: this.buildParams(params),
    });
  }

  getAgentMetrics(params?: FiltroDeTiempoParams): Observable<MetricasDeAgenteResponse> {
    return this.http.get<MetricasDeAgenteResponse>(`${this.baseUrl}/api/reports/agents`, {
      params: this.buildParams(params),
    });
  }

  // ── Reporting v2 methods ──

  /** GET /api/reports/agent-load */
  getAgentLoad(params?: CargaDeAgenteParams): Observable<CargaDeAgenteEntry[]> {
    let httpParams = new HttpParams();
    if (params?.agentId) {
      httpParams = httpParams.set('agent_id', String(params.agentId));
    }
    return this.http.get<CargaDeAgenteEntry[]>(`${this.baseUrl}/api/reports/agent-load`, {
      params: httpParams,
    });
  }

  /** GET /api/reports/unassigned */
  getUnassigned(params?: SinAsignarParams): Observable<SinAsignarResponse> {
    let httpParams = this.buildParams(params);
    if (params?.phoneNumberId) {
      httpParams = httpParams.set('phone_number_id', params.phoneNumberId);
    }
    return this.http.get<SinAsignarResponse>(`${this.baseUrl}/api/reports/unassigned`, {
      params: httpParams,
    });
  }

  /** GET /api/reports/available-agents */
  getAvailableAgents(): Observable<AgenteDisponibleEntry[]> {
    return this.http.get<AgenteDisponibleEntry[]>(`${this.baseUrl}/api/reports/available-agents`);
  }

  /** GET /api/reports/frt-trend */
  getFrtTrend(params?: TendenciaDeFrtParams): Observable<TendenciaDeFrtPoint[]> {
    return this.http.get<TendenciaDeFrtPoint[]>(`${this.baseUrl}/api/reports/frt-trend`, {
      params: this.buildParams(params),
    });
  }

  /** GET /api/reports/heatmap */
  getHeatmap(params?: MapaDeCalorParams): Observable<MapaDeCalorCell[]> {
    return this.http.get<MapaDeCalorCell[]>(`${this.baseUrl}/api/reports/heatmap`, {
      params: this.buildParams(params),
    });
  }

  /** GET /api/reports/recurring-customers */
  getRecurringCustomers(params?: ContactosRecurrentesParams): Observable<ContactoRecurrenteEntry[]> {
    let httpParams = new HttpParams();
    if (params?.phoneNumberId) {
      httpParams = httpParams.set('phone_number_id', params.phoneNumberId);
    }
    if (params?.threshold) {
      httpParams = httpParams.set('threshold', String(params.threshold));
    }
    return this.http.get<ContactoRecurrenteEntry[]>(`${this.baseUrl}/api/reports/recurring-customers`, {
      params: httpParams,
    });
  }

  /** GET /api/reports/reopened */
  getReopened(params?: FiltroDeTiempoParams): Observable<ReabiertoEntry[]> {
    return this.http.get<ReabiertoEntry[]>(`${this.baseUrl}/api/reports/reopened`, {
      params: this.buildParams(params),
    });
  }

  /**
   * GET /api/reports/export — streamed CSV download.
   *
   * `ROLE_SUPERVISOR`-gated on the backend (admins inherit). Returns the raw
   * response body as a `Blob` so the caller can trigger a client-side file
   * save; filters reuse the same `from`/`to` params as every other reports
   * endpoint.
   */
  exportTicketsCsv(params?: FiltroDeTiempoParams): Observable<Blob> {
    return this.http.get(`${this.baseUrl}/api/reports/export`, {
      params: this.buildParams(params),
      responseType: 'blob',
    });
  }

  /** GET /api/reports/response-delay */
  getResponseDelay(params?: FiltroDeTiempoParams): Observable<ReporteDeDemoraDeRespuesta> {
    return this.http.get<ReporteDeDemoraDeRespuesta>(`${this.baseUrl}/api/reports/response-delay`, {
      params: this.buildParams(params),
    });
  }

  /**
   * GET /api/reports/tickets/{id}/participation — per-ticket participation
   * drill-down for the inbox "Participación" panel. Errors become a plain
   * `Error` carrying the backend's message (via `extractApiError`).
   */
  getTicketParticipation(ticketId: number): Observable<ParticipacionDeTicket> {
    return this.http
      .get<ParticipacionDeTicket>(`${this.baseUrl}/api/reports/tickets/${ticketId}/participation`)
      .pipe(
        catchError((err) => {
          const message = extractApiError(err, 'No se pudo cargar la participación del ticket');
          return throwError(() => new Error(message));
        }),
      );
  }
}
