import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { WorkspaceApiService } from '../../../api/workspace-api.service';
import { extractApiError } from '../../shared/extract-api-error';

export interface DeactivateWorkspaceInput {
  id: number;
}

export class WorkspaceDeactivateFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'WorkspaceDeactivateFailed';
  }
}

@Injectable({ providedIn: 'root' })
export class DeactivateWorkspaceUseCase {
  constructor(private readonly api: WorkspaceApiService = inject(WorkspaceApiService)) {}

  execute(input: DeactivateWorkspaceInput): Observable<void> {
    return this.api.deactivate(input.id).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'Failed to deactivate workspace');
        return throwError(() => new WorkspaceDeactivateFailed(message, err));
      }),
    );
  }
}

export function createDeactivateWorkspaceUseCase(api?: WorkspaceApiService): DeactivateWorkspaceUseCase {
  return new DeactivateWorkspaceUseCase(api);
}
