import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of, map, tap, catchError } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface UrlDeAdjuntoResponse {
  url: string;
  vencimientoAt: string;
}

interface CachedUrl {
  url: string;
  expiresAt: Date;
}

/**
 * UrlDeAdjuntoService — fetches and caches pre-signed media URLs.
 *
 * Caches by mensajeId until vencimientoAt minus 30s grace period.
 * Re-fetches automatically on 403 (expired URL).
 */
@Injectable({ providedIn: 'root' })
export class UrlDeAdjuntoService {
  private http = inject(HttpClient);
  private cache = new Map<string, CachedUrl>();

  private readonly GRACE_SECONDS = 30;

  /**
   * Get a pre-signed URL for a message's media attachment.
   * Returns cached URL if still valid, otherwise fetches a new one.
   */
  getUrlDeAdjunto(mensajeId: string): Observable<UrlDeAdjuntoResponse> {
    const cached = this.cache.get(mensajeId);
    const now = new Date();

    // Return cached if valid (with grace period)
    if (cached && cached.expiresAt.getTime() - this.GRACE_SECONDS * 1000 > now.getTime()) {
      return of({ url: cached.url, vencimientoAt: cached.expiresAt.toISOString() });
    }

    return this.fetchUrl(mensajeId).pipe(
      tap((response) => {
        this.cache.set(mensajeId, {
          url: response.url,
          expiresAt: new Date(response.vencimientoAt),
        });
      }),
      catchError((error) => {
        // On 403, clear cache and retry once
        if (error.status === 403) {
          this.cache.delete(mensajeId);
          return this.fetchUrl(mensajeId).pipe(
            tap((response) => {
              this.cache.set(mensajeId, {
                url: response.url,
                expiresAt: new Date(response.vencimientoAt),
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
  clearCache(mensajeId?: string): void {
    if (mensajeId) {
      this.cache.delete(mensajeId);
    } else {
      this.cache.clear();
    }
  }

  private fetchUrl(mensajeId: string): Observable<UrlDeAdjuntoResponse> {
    return this.http.get<UrlDeAdjuntoResponse>(
      `${environment.apiUrl}/api/mensajes/${mensajeId}/adjunto`
    );
  }
}
