import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { Ticket, EstadoDeTicket } from './ticket.model';
import { environment } from '../../../environments/environment';
import { createHttpErrorHandler } from '../../core/http/http-error.util';

@Injectable({ providedIn: 'root' })
export class FlujoDeTicketApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  transition(ticketId: string, newStatus: EstadoDeTicket): Observable<Ticket> {
    return this.http
      .post<Ticket>(`${this.baseUrl}/api/tickets/${ticketId}/transition`, {
        status: newStatus
      })
      .pipe(catchError(createHttpErrorHandler('WorkflowApi')));
  }

  /**
   * Resolve a ticket.
   *
   * Unlike `transition()`, this deliberately does NOT pipe through
   * `createHttpErrorHandler`: that helper flattens the response into a generic
   * `Error` and drops the status code. Callers need to tell an authorization
   * failure (403 — not admin, supervisor, nor current owner) apart from any
   * other failure in order to show the right message, so the raw
   * `HttpErrorResponse` is propagated instead.
   */
  resolve(ticketId: string): Observable<Ticket> {
    return this.http.post<Ticket>(
      `${this.baseUrl}/api/tickets/${ticketId}/resolve`,
      {}
    );
  }

  /**
   * Close a ticket. Propagates the raw `HttpErrorResponse` for the same reason
   * as `resolve()`.
   */
  close(ticketId: string): Observable<Ticket> {
    return this.http.post<Ticket>(
      `${this.baseUrl}/api/tickets/${ticketId}/close`,
      {}
    );
  }
}
