import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TeamDTO } from '../administracion/team.dto';
import { environment } from '../../../environments/environment';
import { createHttpErrorHandler } from '../../core/http/http-error.util';

/**
 * TeamListApiService — HTTP client for the non-admin team listing.
 *
 * Endpoints:
 * - GET /api/teams — workspace-scoped listing for the inbox/reports team
 *   filter dropdowns. Includes deactivated teams — per spec, a deactivated
 *   team must remain selectable for historical filtering even though it's
 *   hidden from admin "new assignment" pickers.
 *
 * Team CRUD (`/api/admin/teams`) lives in `TeamApiService` (administracion).
 */
@Injectable({ providedIn: 'root' })
export class TeamListApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** GET /api/teams */
  listTeams(): Observable<TeamDTO[]> {
    return this.http.get<TeamDTO[]>(`${this.baseUrl}/api/teams`).pipe(
      catchError(createHttpErrorHandler('TeamApi'))
    );
  }
}
