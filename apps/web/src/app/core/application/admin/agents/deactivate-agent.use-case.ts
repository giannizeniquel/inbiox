import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService } from '../../../api/admin-api.service';
import { extractApiError } from '../../shared/extract-api-error';

/**
 * DeactivateAgentInput — the ID of the agent to deactivate.
 */
export interface DeactivateAgentInput {
  id: number;
}

/**
 * DeactivateAgentOutput — the ID of the deactivated agent.
 */
export interface DeactivateAgentOutput {
  id: number;
}

/**
 * AgentDeactivationFailed — exception thrown when the agent deactivation API call fails.
 */
export class AgentDeactivationFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'AgentDeactivationFailed';
  }
}

/**
 * DeactivateAgentUseCase — soft-deletes an agent by calling the delete endpoint.
 *
 * The backend performs a soft delete (active = false). This use case wraps
 * AdminApiService.deleteAgent and returns the agent ID on success.
 */
@Injectable({ providedIn: 'root' })
export class DeactivateAgentUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: DeactivateAgentInput): Observable<DeactivateAgentOutput> {
    return this.api.deleteAgent(input.id).pipe(
      map(() => ({ id: input.id })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to deactivate agent');
        return throwError(() => new AgentDeactivationFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createDeactivateAgentUseCase(api?: AdminApiService): DeactivateAgentUseCase {
  return new DeactivateAgentUseCase(api);
}
