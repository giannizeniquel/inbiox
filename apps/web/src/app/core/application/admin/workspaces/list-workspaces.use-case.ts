import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { WorkspaceApiService } from '../../../api/workspace-api.service';
import { WorkspaceDTO } from '../../../dto/workspace.dto';
import { extractApiError } from '../../shared/extract-api-error';

export interface ListWorkspacesInput {
  page: number;
  limit?: number;
}

export interface ListWorkspacesOutput {
  workspaces: WorkspaceDTO[];
  totalPages: number;
  total: number;
}

export class WorkspaceListFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'WorkspaceListFailed';
  }
}

@Injectable({ providedIn: 'root' })
export class ListWorkspacesUseCase {
  constructor(private readonly api: WorkspaceApiService = inject(WorkspaceApiService)) {}

  execute(input: ListWorkspacesInput): Observable<ListWorkspacesOutput> {
    return this.api.listAll(input.page, input.limit).pipe(
      map((res) => ({
        workspaces: res.data,
        totalPages: Math.max(1, Math.ceil(res.meta.total / res.meta.limit)),
        total: res.meta.total,
      })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to load workspaces');
        return throwError(() => new WorkspaceListFailed(message, err));
      }),
    );
  }
}

export function createListWorkspacesUseCase(api?: WorkspaceApiService): ListWorkspacesUseCase {
  return new ListWorkspacesUseCase(api);
}
