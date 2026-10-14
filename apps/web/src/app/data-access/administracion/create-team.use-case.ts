import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TeamApiService } from './team-api.service';
import { TeamDTO } from './team.dto';
import { extractApiError } from '../../core/http/extract-api-error';

export interface CreateTeamInput {
  workspaceId: number;
  name: string;
  isActive?: boolean;
}

export class TeamCreateFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'TeamCreateFailed';
  }
}

@Injectable({ providedIn: 'root' })
export class CreateTeamUseCase {
  constructor(private readonly api: TeamApiService = inject(TeamApiService)) {}

  execute(input: CreateTeamInput): Observable<TeamDTO> {
    return this.api.create(input).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'Failed to create team');
        return throwError(() => new TeamCreateFailed(message, err));
      }),
    );
  }
}

export function createCreateTeamUseCase(api?: TeamApiService): CreateTeamUseCase {
  return new CreateTeamUseCase(api);
}
