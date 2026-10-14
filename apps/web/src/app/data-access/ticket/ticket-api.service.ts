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
   * @param teamId Optional single-select team filter — forwarded to
   *               `GET /api/tickets?teamId=...` (team-area-entity, Slice 3).
   *               Composable with any future server-side filter params.
   */
  getTickets(teamId?: number | null): Observable<Ticket[]> {
    let params = new HttpParams();
    if (teamId !== null && teamId !== undefined) {
      params = params.set('teamId', String(teamId));
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

  /**
   * Assign or reassign a ticket to an agent.
   *
   * POST /api/tickets/{id}/assign
   */
  assignTicket(
    ticketId: string,
    agentId: number,
    assignedBy: string,
    notes?: string
  ): Observable<Ticket> {
    return this.http.post<Ticket>(
      `${this.baseUrl}/api/tickets/${ticketId}/assign`,
      { agentId, assignedBy, notes }
    ).pipe(
      catchError(createHttpErrorHandler('TicketApi'))
    );
  }
}
