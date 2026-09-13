import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TeamDTO } from '../dto/team.dto';
import { environment } from '../../../environments/environment';
import { createHttpErrorHandler } from '../utils/http-error.util';

/**
 * TeamApiService — HTTP client for the non-admin, workspace-scoped
 * `GET /api/teams` endpoint (Tier 2 #7, Slice 1). Used by the inbox and
 * reports team filter dropdowns (Slice 3). Includes deactivated teams —
 * per spec, a deactivated team must remain selectable for historical
 * filtering even though it's hidden from admin "new assignment" pickers.
 */
@Injectable({ providedIn: 'root' })
export class TeamApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  listTeams(): Observable<TeamDTO[]> {
    return this.http.get<TeamDTO[]>(`${this.baseUrl}/api/teams`).pipe(
      catchError(createHttpErrorHandler('TeamApi'))
    );
  }
}
