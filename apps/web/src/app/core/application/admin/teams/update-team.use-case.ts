import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TeamApiService } from '../../../api/team-api.service';
import { TeamDTO } from '../../../dto/team.dto';
import { extractApiError } from '../../shared/extract-api-error';

export interface UpdateTeamInput {
  id: number;
  name?: string;
  isActive?: boolean;
}

export class TeamUpdateFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'TeamUpdateFailed';
  }
}

@Injectable({ providedIn: 'root' })
export class UpdateTeamUseCase {
  constructor(private readonly api: TeamApiService = inject(TeamApiService)) {}

  execute(input: UpdateTeamInput): Observable<TeamDTO> {
    const { id, ...payload } = input;
    return this.api.update(id, payload).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'Failed to update team');
        return throwError(() => new TeamUpdateFailed(message, err));
      }),
    );
  }
}

export function createUpdateTeamUseCase(api?: TeamApiService): UpdateTeamUseCase {
  return new UpdateTeamUseCase(api);
}
