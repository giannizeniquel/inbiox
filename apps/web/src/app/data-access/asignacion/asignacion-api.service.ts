import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { Ticket } from '../ticket/ticket.model';
import { environment } from '../../../environments/environment';
import { createHttpErrorHandler } from '../../core/http/http-error.util';

/**
 * AsignacionApiService — HTTP client for ticket assignment endpoints.
 *
 * Endpoints:
 * - POST /api/tickets/{id}/assign — assign or reassign a ticket to an agent
 */
@Injectable({ providedIn: 'root' })
export class AsignacionApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /**
   * Assign or reassign a ticket to an agent.
   *
   * POST /api/tickets/{id}/assign
   */
  asignarTicket(
    ticketId: string,
    agenteId: number,
    asignadoPor: string,
    notes?: string
  ): Observable<Ticket> {
    return this.http.post<Ticket>(
      `${this.baseUrl}/api/tickets/${ticketId}/assign`,
      { agentId: agenteId, assignedBy: asignadoPor, notes }
    ).pipe(
      catchError(createHttpErrorHandler('TicketApi'))
    );
  }
}
