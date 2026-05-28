import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError, timer, MonoTypeOperatorFunction } from 'rxjs';
import { catchError, retry } from 'rxjs/operators';
import { Message, SendMessageRequest } from '../models/message.model';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class MessageApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  getMessages(ticketId: string): Observable<Message[]> {
    return this.http
      .get<Message[]>(`${this.baseUrl}/tickets/${ticketId}/messages`)
      .pipe(
        this.retryWithBackoff(),
        catchError(this.handleError)
      );
  }

  sendMessage(ticketId: string, request: SendMessageRequest): Observable<Message> {
    return this.http
      .post<Message>(`${this.baseUrl}/tickets/${ticketId}/messages`, request)
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
        console.warn(`[MessageApiService] Retry attempt ${retryCount}/3 after ${delay}ms`);
        return timer(delay);
      },
    }) as MonoTypeOperatorFunction<T>;
  }

  private handleError(error: HttpErrorResponse): Observable<never> {
    console.error('[MessageApiService] HTTP Error:', error);
    return throwError(
      () => new Error(`Message API error: ${error.status} - ${error.message}`)
    );
  }
}