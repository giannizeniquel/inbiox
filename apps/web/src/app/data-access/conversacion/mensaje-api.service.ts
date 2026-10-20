import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { MensajeDto } from './mensaje.model';
import { environment } from '../../../environments/environment';
import { retryWithBackoff } from '../../core/http/retry.util';
import { createHttpErrorHandler } from '../../core/http/http-error.util';

export interface GetMensajesResponse {
  data: MensajeDto[];
  meta: { has_more: boolean; next_cursor?: string };
}

@Injectable({ providedIn: 'root' })
export class MensajeApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  getMessages(ticketId: string, limit = 20, offset = 0): Observable<GetMensajesResponse> {
    const params: Record<string, string> = {
      limit: limit.toString(),
      offset: offset.toString(),
    };
    const call = this.http.get<GetMensajesResponse>(
      `${this.baseUrl}/api/tickets/${ticketId}/messages`,
      { params }
    );
    return retryWithBackoff(call).pipe(
      catchError(createHttpErrorHandler('MessageApi'))
    );
  }
}
