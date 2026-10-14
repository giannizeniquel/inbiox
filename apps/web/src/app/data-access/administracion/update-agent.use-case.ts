import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService, AdminAgentDTO } from './admin-api.service';
import { extractApiError } from '../../core/http/extract-api-error';

/**
 * UpdateAgentInput — parameters for updating an existing agent.
 */
export interface UpdateAgentInput {
  id: number;
  name: string;
  active: boolean;
  timezone: string;
}

/**
 * UpdateAgentOutput — the updated agent.
 */
export interface UpdateAgentOutput {
  agent: AdminAgentDTO;
}

/**
 * AgentUpdateFailed — exception thrown when the agent update API call fails.
 */
export class AgentUpdateFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'AgentUpdateFailed';
  }
}

/**
 * UpdateAgentUseCase — updates an existing agent with full payload.
 *
 * Always sends the full payload (name + active), matching the current component behavior.
 * The name is trimmed before sending.
 */
@Injectable({ providedIn: 'root' })
export class UpdateAgentUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: UpdateAgentInput): Observable<UpdateAgentOutput> {
    return this.api.updateAgent(input.id, {
      name: input.name.trim(),
      active: input.active,
      timezone: input.timezone,
    }).pipe(
      map((agent) => ({ agent })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to update agent');
        return throwError(() => new AgentUpdateFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createUpdateAgentUseCase(api?: AdminApiService): UpdateAgentUseCase {
  return new UpdateAgentUseCase(api);
}
