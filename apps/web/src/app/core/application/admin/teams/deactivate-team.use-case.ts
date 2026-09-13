import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TeamApiService } from '../../../api/team-api.service';
import { extractApiError } from '../../shared/extract-api-error';

export interface DeactivateTeamInput {
  id: number;
}

export class TeamDeactivateFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'TeamDeactivateFailed';
  }
}

@Injectable({ providedIn: 'root' })
export class DeactivateTeamUseCase {
  constructor(private readonly api: TeamApiService = inject(TeamApiService)) {}

  execute(input: DeactivateTeamInput): Observable<void> {
    return this.api.deactivate(input.id).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'Failed to deactivate team');
        return throwError(() => new TeamDeactivateFailed(message, err));
      }),
    );
  }
}

export function createDeactivateTeamUseCase(api?: TeamApiService): DeactivateTeamUseCase {
  return new DeactivateTeamUseCase(api);
}
