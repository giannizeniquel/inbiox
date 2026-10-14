import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of, map, tap, catchError } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface MediaUrlResponse {
  url: string;
  expiresAt: string;
}

interface CachedUrl {
  url: string;
  expiresAt: Date;
}

/**
 * MediaUrlService — fetches and caches pre-signed media URLs.
 *
 * Caches by messageId until expiresAt minus 30s grace period.
 * Re-fetches automatically on 403 (expired URL).
 */
@Injectable({ providedIn: 'root' })
export class MediaUrlService {
  private http = inject(HttpClient);
  private cache = new Map<string, CachedUrl>();

  private readonly GRACE_SECONDS = 30;

  /**
   * Get a pre-signed URL for a message's media attachment.
   * Returns cached URL if still valid, otherwise fetches a new one.
   */
  getMediaUrl(messageId: string): Observable<MediaUrlResponse> {
    const cached = this.cache.get(messageId);
    const now = new Date();

    // Return cached if valid (with grace period)
    if (cached && cached.expiresAt.getTime() - this.GRACE_SECONDS * 1000 > now.getTime()) {
      return of({ url: cached.url, expiresAt: cached.expiresAt.toISOString() });
    }

    return this.fetchUrl(messageId).pipe(
      tap((response) => {
        this.cache.set(messageId, {
          url: response.url,
          expiresAt: new Date(response.expiresAt),
        });
      }),
      catchError((error) => {
        // On 403, clear cache and retry once
        if (error.status === 403) {
          this.cache.delete(messageId);
          return this.fetchUrl(messageId).pipe(
            tap((response) => {
              this.cache.set(messageId, {
                url: response.url,
                expiresAt: new Date(response.expiresAt),
              });
            })
          );
        }
        throw error;
      })
    );
  }

  /**
   * Clear cached URL for a message (e.g. on logout).
   */
  clearCache(messageId?: string): void {
    if (messageId) {
      this.cache.delete(messageId);
    } else {
      this.cache.clear();
    }
  }

  private fetchUrl(messageId: string): Observable<MediaUrlResponse> {
    return this.http.get<MediaUrlResponse>(
      `${environment.apiUrl}/api/messages/${messageId}/media`
    );
  }
}
