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

/** Backend response for GET /api/reportes/frt */
export interface FirstResponseTimeResponse {
  frtPromedioMinutos: number;
}

/** Backend response for GET /api/reportes/rt */
export interface ResolutionTimeResponse {
  rtPromedioMinutos: number;
}

/** Backend response for GET /api/reportes/conteos */
export interface ConteosDeTicketsResponse {
  nuevo: number;
  activo: number;
  en_espera: number;
  resuelto: number;
  cerrado: number;
}

/** Backend response for GET /api/reportes/volumen — array of daily data */
export interface VolumenDataPoint {
  fecha: string;
  ticketsCreados: number;
}

export interface TendenciaDeVolumenResponse {
  data: VolumenDataPoint[];
  total: number;
  trend: number;
}

/** Backend response for GET /api/reportes/rendimiento-de-agentes */
export interface MetricasDeAgenteData {
  agenteId: number;
  ticketsAsignados: number;
  frtPromedioMinutos: number;
  ticketsResueltos: number;
}

export interface MetricasDeAgenteResponse {
  agents: MetricasDeAgenteData[];
}

// ── Reporting v2 response interfaces ──

/** Backend response for GET /api/reportes/carga-de-agentes */
export interface CargaDeAgenteEntry {
  agenteId: number;
  agenteNombre: string;
  ticketsActivos: number;
  capacidadMaxima: number;
  porcentajeDeCarga: number;
}

/** Backend response for GET /api/reportes/sin-asignar */
export interface SinAsignarResponse {
  cantidad: number;
  tickets: Array<{
    id: number;
    contactoNombre: string | null;
    creadoAt: string;
    prioridad: string;
  }>;
}

/** Backend response for GET /api/reportes/agentes-disponibles */
export interface AgenteDisponibleEntry {
  agenteId: number;
  agenteNombre: string;
  enTurno: boolean;
  ticketsActivos: number;
}

/** Backend response for GET /api/reportes/tendencia-de-frt */
export interface TendenciaDeFrtPoint {
  fecha: string;
  frtPromedioSegundos: number;
  cantidadDeTickets: number;
}

/** Backend response for GET /api/reportes/mapa-de-calor */
export interface MapaDeCalorCell {
  diaDeLaSemana: number;
  hora: number;
  cantidad: number;
}

/** Backend response for GET /api/reportes/contactos-recurrentes */
export interface ContactoRecurrenteEntry {
  contactoId: number;
  contactoNombre: string | null;
  email: string | null;
  cantidadDeTickets: number;
  ultimoTicketAt: string;
}

/** Backend response for GET /api/reportes/reabiertos */
export interface ReabiertoEntry {
  ticketId: number;
  contactoNombre: string | null;
  resueltoAt: string | null;
  reabiertoAt: string;
  estadoActual: string;
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
      httpParams = httpParams.set('desde', from);
    }

    if (params?.toDate) {
      const to = params.toDate.length === 10
        ? `${params.toDate}T23:59:59Z`
        : params.toDate;
      httpParams = httpParams.set('hasta', to);
    }

    if (params?.teamId !== undefined && params.teamId !== null) {
      httpParams = httpParams.set('equipoId', String(params.teamId));
    }

    return httpParams;
  }

  // ── Existing methods ──

  getFirstResponseTime(params?: FiltroDeTiempoParams): Observable<FirstResponseTimeResponse> {
    return this.http.get<FirstResponseTimeResponse>(`${this.baseUrl}/api/reportes/frt`, {
      params: this.buildParams(params),
    });
  }

  getResolutionTime(params?: FiltroDeTiempoParams): Observable<ResolutionTimeResponse> {
    return this.http.get<ResolutionTimeResponse>(`${this.baseUrl}/api/reportes/rt`, {
      params: this.buildParams(params),
    });
  }

  getConteosDeTickets(params?: FiltroDeTiempoParams): Observable<ConteosDeTicketsResponse> {
    return this.http.get<ConteosDeTicketsResponse>(`${this.baseUrl}/api/reportes/conteos`, {
      params: this.buildParams(params),
    });
  }

  getTendenciaDeVolumen(params?: FiltroDeTiempoParams): Observable<TendenciaDeVolumenResponse> {
    return this.http.get<TendenciaDeVolumenResponse>(`${this.baseUrl}/api/reportes/volumen`, {
      params: this.buildParams(params),
    });
  }

  getMetricasDeAgente(params?: FiltroDeTiempoParams): Observable<MetricasDeAgenteResponse> {
    return this.http.get<MetricasDeAgenteResponse>(`${this.baseUrl}/api/reportes/rendimiento-de-agentes`, {
      params: this.buildParams(params),
    });
  }

  // ── Reporting v2 methods ──

  /** GET /api/reportes/carga-de-agentes */
  getCargaDeAgente(params?: CargaDeAgenteParams): Observable<CargaDeAgenteEntry[]> {
    let httpParams = new HttpParams();
    if (params?.agentId) {
      httpParams = httpParams.set('agenteId', String(params.agentId));
    }
    return this.http.get<CargaDeAgenteEntry[]>(`${this.baseUrl}/api/reportes/carga-de-agentes`, {
      params: httpParams,
    });
  }

  /** GET /api/reportes/sin-asignar */
  getSinAsignar(params?: SinAsignarParams): Observable<SinAsignarResponse> {
    let httpParams = this.buildParams(params);
    if (params?.phoneNumberId) {
      httpParams = httpParams.set('phone_number_id', params.phoneNumberId);
    }
    return this.http.get<SinAsignarResponse>(`${this.baseUrl}/api/reportes/sin-asignar`, {
      params: httpParams,
    });
  }

  /** GET /api/reportes/agentes-disponibles */
  getAgentesDisponibles(): Observable<AgenteDisponibleEntry[]> {
    return this.http.get<AgenteDisponibleEntry[]>(`${this.baseUrl}/api/reportes/agentes-disponibles`);
  }

  /** GET /api/reportes/tendencia-de-frt */
  getTendenciaDeFrt(params?: TendenciaDeFrtParams): Observable<TendenciaDeFrtPoint[]> {
    return this.http.get<TendenciaDeFrtPoint[]>(`${this.baseUrl}/api/reportes/tendencia-de-frt`, {
      params: this.buildParams(params),
    });
  }

  /** GET /api/reportes/mapa-de-calor */
  getMapaDeCalor(params?: MapaDeCalorParams): Observable<MapaDeCalorCell[]> {
    return this.http.get<MapaDeCalorCell[]>(`${this.baseUrl}/api/reportes/mapa-de-calor`, {
      params: this.buildParams(params),
    });
  }

  /** GET /api/reportes/contactos-recurrentes */
  getContactosRecurrentes(params?: ContactosRecurrentesParams): Observable<ContactoRecurrenteEntry[]> {
    let httpParams = new HttpParams();
    if (params?.phoneNumberId) {
      httpParams = httpParams.set('phone_number_id', params.phoneNumberId);
    }
    if (params?.threshold) {
      httpParams = httpParams.set('umbral', String(params.threshold));
    }
    return this.http.get<ContactoRecurrenteEntry[]>(`${this.baseUrl}/api/reportes/contactos-recurrentes`, {
      params: httpParams,
    });
  }

  /** GET /api/reportes/reabiertos */
  getReabiertos(params?: FiltroDeTiempoParams): Observable<ReabiertoEntry[]> {
    return this.http.get<ReabiertoEntry[]>(`${this.baseUrl}/api/reportes/reabiertos`, {
      params: this.buildParams(params),
    });
  }

  /**
   * GET /api/reportes/exportar — streamed CSV download.
   *
   * `ROLE_SUPERVISOR`-gated on the backend (admins inherit). Returns the raw
   * response body as a `Blob` so the caller can trigger a client-side file
   * save; filters reuse the same `from`/`to` params as every other reports
   * endpoint.
   */
  exportarTicketsCsv(params?: FiltroDeTiempoParams): Observable<Blob> {
    return this.http.get(`${this.baseUrl}/api/reportes/exportar`, {
      params: this.buildParams(params),
      responseType: 'blob',
    });
  }

  /** GET /api/reportes/demora-de-respuesta */
  getDemoraDeRespuesta(params?: FiltroDeTiempoParams): Observable<ReporteDeDemoraDeRespuesta> {
    return this.http.get<ReporteDeDemoraDeRespuesta>(`${this.baseUrl}/api/reportes/demora-de-respuesta`, {
      params: this.buildParams(params),
    });
  }

  /**
   * GET /api/reportes/tickets/{id}/participacion — per-ticket participation
   * drill-down for the inbox "Participación" panel. Errors become a plain
   * `Error` carrying the backend's message (via `extractApiError`).
   */
  getParticipacionDeTicket(ticketId: number): Observable<ParticipacionDeTicket> {
    return this.http
      .get<ParticipacionDeTicket>(`${this.baseUrl}/api/reportes/tickets/${ticketId}/participacion`)
      .pipe(
        catchError((err) => {
          const message = extractApiError(err, 'No se pudo cargar la participación del ticket');
          return throwError(() => new Error(message));
        }),
      );
  }
}
