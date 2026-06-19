import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError, map, timer, MonoTypeOperatorFunction } from 'rxjs';
import { catchError, retry } from 'rxjs/operators';
import { Ticket, TicketListResponse } from '../models/ticket.model';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class TicketApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  getTickets(): Observable<Ticket[]> {
    return this.http
      .get<{ data: Ticket[]; meta: { total: number; page: number } }>(`${this.baseUrl}/api/tickets`)
      .pipe(
        this.retryWithBackoff(),
        map((response) => response.data),
        catchError(this.handleError)
      );
  }

  getTicket(id: string): Observable<Ticket> {
    return this.http
      .get<Ticket>(`${this.baseUrl}/api/tickets/${id}`)
      .pipe(
        this.retryWithBackoff(),
        catchError(this.handleError)
      );
  }

  /**
   * Retry with exponential backoff (max 3 retries).
   */
  private retryWithBackoff<T>(): MonoTypeOperatorFunction<T> {
    return retry({
      count: 3,
      delay: (err, retryCount) => {
        const delay = Math.pow(2, retryCount) * 1000;
        console.warn(`[TicketApiService] Retry attempt ${retryCount}/3 after ${delay}ms`);
        return timer(delay);
      },
    }) as MonoTypeOperatorFunction<T>;
  }

  private handleError(error: HttpErrorResponse): Observable<never> {
    console.error('[TicketApiService] HTTP Error:', error);
    return throwError(
      () => new Error(`Ticket API error: ${error.status} - ${error.message}`)
    );
  }
}