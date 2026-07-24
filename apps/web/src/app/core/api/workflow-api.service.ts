import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { Ticket, TicketStatus } from '../models/ticket.model';
import { environment } from '../../../environments/environment';
import { createHttpErrorHandler } from '../utils/http-error.util';

@Injectable({ providedIn: 'root' })
export class WorkflowApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  transition(ticketId: string, newStatus: TicketStatus): Observable<Ticket> {
    return this.http
      .post<Ticket>(`${this.baseUrl}/api/tickets/${ticketId}/transition`, {
        status: newStatus
      })
      .pipe(catchError(createHttpErrorHandler('WorkflowApi')));
  }
}
