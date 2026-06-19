import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError, timer, MonoTypeOperatorFunction } from 'rxjs';
import { catchError, retry } from 'rxjs/operators';
import { Message, SendMessageRequest } from '../models/message.model';
import { environment } from '../../../environments/environment';

export interface GetMessagesResponse {
  data: Message[];
  meta: { has_more: boolean; next_cursor?: string };
}

@Injectable({ providedIn: 'root' })
export class MessageApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  getMessages(ticketId: string, limit = 20, offset = 0): Observable<GetMessagesResponse> {
    const params: Record<string, string> = {
      limit: limit.toString(),
      offset: offset.toString(),
    };
    return this.http
      .get<GetMessagesResponse>(`${this.baseUrl}/api/tickets/${ticketId}/messages`, { params })
      .pipe(
        this.retryWithBackoff(),
        catchError(this.handleError)
      );
  }

  sendMessage(ticketId: string, request: SendMessageRequest): Observable<Message> {
    return this.http
      .post<Message>(`${this.baseUrl}/api/tickets/${ticketId}/messages`, request)
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