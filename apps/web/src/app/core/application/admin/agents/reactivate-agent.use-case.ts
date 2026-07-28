import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService } from '../../../api/admin-api.service';
import { extractApiError } from '../../shared/extract-api-error';

/**
 * ReactivateAgentInput — the ID of the agent to reactivate.
 */
export interface ReactivateAgentInput {
  id: number;
}

/**
 * ReactivateAgentOutput — the ID of the reactivated agent.
 */
export interface ReactivateAgentOutput {
  id: number;
}

/**
 * AgentReactivationFailed — exception thrown when the agent reactivation API call fails.
 */
export class AgentReactivationFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'AgentReactivationFailed';
  }
}

/**
 * ReactivateAgentUseCase — reactivates a previously deactivated agent.
 *
 * Calls AdminApiService.updateAgent with { active: true } to re-enable the agent.
 */
@Injectable({ providedIn: 'root' })
export class ReactivateAgentUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: ReactivateAgentInput): Observable<ReactivateAgentOutput> {
    return this.api.updateAgent(input.id, { active: true }).pipe(
      map(() => ({ id: input.id })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to reactivate agent');
        return throwError(() => new AgentReactivationFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createReactivateAgentUseCase(api?: AdminApiService): ReactivateAgentUseCase {
  return new ReactivateAgentUseCase(api);
}
