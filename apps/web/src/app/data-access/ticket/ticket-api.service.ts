import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { Ticket } from './ticket.model';
import { environment } from '../../../environments/environment';
import { retryWithBackoff } from '../../core/http/retry.util';
import { createHttpErrorHandler } from '../../core/http/http-error.util';

@Injectable({ providedIn: 'root' })
export class TicketApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /**
   * @param equipoId Optional single-select team filter — forwarded to
   *               `GET /api/tickets?equipoId=...` (team-area-entity, Slice 3).
   *               Composable with any future server-side filter params.
   */
  getTickets(equipoId?: number | null): Observable<Ticket[]> {
    let params = new HttpParams();
    if (equipoId !== null && equipoId !== undefined) {
      params = params.set('equipoId', String(equipoId));
    }

    const call = this.http.get<{ data: Ticket[]; meta: { total: number; page: number } }>(
      `${this.baseUrl}/api/tickets`,
      { params }
    );
    return retryWithBackoff(call).pipe(
      map((response) => response.data),
      catchError(createHttpErrorHandler('TicketApi'))
    );
  }

  getTicket(id: string): Observable<Ticket> {
    const call = this.http.get<Ticket>(`${this.baseUrl}/api/tickets/${id}`);
    return retryWithBackoff(call).pipe(
      catchError(createHttpErrorHandler('TicketApi'))
    );
  }

  /** `PUT /api/tickets/{id}/marcar-leido`: single attempt, no retry; the caller decides how to handle errors. */
  marcarLeido(ticketId: number): Observable<unknown> {
    return this.http.put(`${this.baseUrl}/api/tickets/${ticketId}/marcar-leido`, {});
  }
}
