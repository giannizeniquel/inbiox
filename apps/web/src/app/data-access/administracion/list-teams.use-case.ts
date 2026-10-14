import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { TeamApiService } from './team-api.service';
import { TeamDTO } from './team.dto';
import { extractApiError } from '../../core/http/extract-api-error';

export interface ListTeamsInput {
  page: number;
  limit?: number;
}

export interface ListTeamsOutput {
  teams: TeamDTO[];
  totalPages: number;
  total: number;
}

export class TeamListFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'TeamListFailed';
  }
}

@Injectable({ providedIn: 'root' })
export class ListTeamsUseCase {
  constructor(private readonly api: TeamApiService = inject(TeamApiService)) {}

  execute(input: ListTeamsInput): Observable<ListTeamsOutput> {
    return this.api.listAll(input.page, input.limit).pipe(
      map((res) => ({
        teams: res.data,
        totalPages: Math.max(1, Math.ceil(res.meta.total / res.meta.limit)),
        total: res.meta.total,
      })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to load teams');
        return throwError(() => new TeamListFailed(message, err));
      }),
    );
  }
}

export function createListTeamsUseCase(api?: TeamApiService): ListTeamsUseCase {
  return new ListTeamsUseCase(api);
}
