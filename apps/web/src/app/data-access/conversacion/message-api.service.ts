import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { MessageDto } from './message.model';
import { environment } from '../../../environments/environment';
import { retryWithBackoff } from '../../core/http/retry.util';
import { createHttpErrorHandler } from '../../core/http/http-error.util';

export interface GetMessagesResponse {
  data: MessageDto[];
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
    const call = this.http.get<GetMessagesResponse>(
      `${this.baseUrl}/api/tickets/${ticketId}/messages`,
      { params }
    );
    return retryWithBackoff(call).pipe(
      catchError(createHttpErrorHandler('MessageApi'))
    );
  }
}
