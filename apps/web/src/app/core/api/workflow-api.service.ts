import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { Ticket, TicketStatus } from '../models/ticket.model';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class WorkflowApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  transition(ticketId: string, newStatus: TicketStatus): Observable<Ticket> {
    return this.http
      .post<Ticket>(`${this.baseUrl}/tickets/${ticketId}/transition`, {
        status: newStatus
      })
      .pipe(catchError(this.handleError));
  }

  private handleError(error: HttpErrorResponse): Observable<never> {
    console.error('[WorkflowApiService] HTTP Error:', error);
    return throwError(
      () => new Error(`Workflow API error: ${error.status} - ${error.message}`)
    );
  }
}