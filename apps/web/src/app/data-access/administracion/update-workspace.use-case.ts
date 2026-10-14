import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { WorkspaceApiService } from './workspace-api.service';
import { WorkspaceDTO } from './workspace.dto';
import { extractApiError } from '../../core/http/extract-api-error';

export interface UpdateWorkspaceInput {
  id: number;
  phoneNumberId?: string;
  displayName?: string;
  isActive?: boolean;
}

export class WorkspaceUpdateFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'WorkspaceUpdateFailed';
  }
}

@Injectable({ providedIn: 'root' })
export class UpdateWorkspaceUseCase {
  constructor(private readonly api: WorkspaceApiService = inject(WorkspaceApiService)) {}

  execute(input: UpdateWorkspaceInput): Observable<WorkspaceDTO> {
    const { id, ...payload } = input;
    return this.api.update(id, payload).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'Failed to update workspace');
        return throwError(() => new WorkspaceUpdateFailed(message, err));
      }),
    );
  }
}

export function createUpdateWorkspaceUseCase(api?: WorkspaceApiService): UpdateWorkspaceUseCase {
  return new UpdateWorkspaceUseCase(api);
}
