import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { WorkspaceApiService } from '../../../api/workspace-api.service';
import { WorkspaceDTO } from '../../../dto/workspace.dto';
import { extractApiError } from '../../shared/extract-api-error';

export interface CreateWorkspaceInput {
  phoneNumberId: string;
  displayName: string;
  isActive?: boolean;
}

export class WorkspaceCreateFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'WorkspaceCreateFailed';
  }
}

@Injectable({ providedIn: 'root' })
export class CreateWorkspaceUseCase {
  constructor(private readonly api: WorkspaceApiService = inject(WorkspaceApiService)) {}

  execute(input: CreateWorkspaceInput): Observable<WorkspaceDTO> {
    return this.api.create(input).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'Failed to create workspace');
        return throwError(() => new WorkspaceCreateFailed(message, err));
      }),
    );
  }
}

export function createCreateWorkspaceUseCase(api?: WorkspaceApiService): CreateWorkspaceUseCase {
  return new CreateWorkspaceUseCase(api);
}
