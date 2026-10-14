import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService, AdminAgentDTO } from './admin-api.service';
import { extractApiError } from '../../core/http/extract-api-error';

/**
 * ListAgentsInput — pagination parameters for agent listing.
 */
export interface ListAgentsInput {
  page: number;
  limit?: number;
}

/**
 * ListAgentsOutput — paginated agent list response.
 */
export interface ListAgentsOutput {
  agents: AdminAgentDTO[];
  totalPages: number;
  total: number;
}

/**
 * AgentListFailed — exception thrown when the agent list API call fails.
 */
export class AgentListFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'AgentListFailed';
  }
}

/**
 * ListAgentsUseCase — retrieves a paginated list of agents.
 *
 * Delegates to AdminApiService.listAgents and maps the paginated response
 * into a flat output with computed totalPages.
 */
@Injectable({ providedIn: 'root' })
export class ListAgentsUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: ListAgentsInput): Observable<ListAgentsOutput> {
    return this.api.listAgents(input.page, input.limit).pipe(
      map((res) => ({
        agents: res.data,
        totalPages: Math.max(1, Math.ceil(res.meta.total / res.meta.limit)),
        total: res.meta.total,
      })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to load agents');
        return throwError(() => new AgentListFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createListAgentsUseCase(api?: AdminApiService): ListAgentsUseCase {
  return new ListAgentsUseCase(api);
}
