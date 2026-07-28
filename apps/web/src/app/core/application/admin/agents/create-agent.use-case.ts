import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService, AdminAgentDTO } from '../../../api/admin-api.service';
import { extractApiError } from '../../shared/extract-api-error';

/**
 * CreateAgentInput — parameters for creating a new agent.
 */
export interface CreateAgentInput {
  name: string;
}

/**
 * CreateAgentOutput — the newly created agent.
 */
export interface CreateAgentOutput {
  agent: AdminAgentDTO;
}

/**
 * InvalidAgentName — exception thrown when the agent name is empty or whitespace-only.
 */
export class InvalidAgentName extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'InvalidAgentName';
  }
}

/**
 * AgentCreationFailed — exception thrown when the agent creation API call fails.
 */
export class AgentCreationFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'AgentCreationFailed';
  }
}

/**
 * CreateAgentUseCase — creates a new agent with validated name.
 *
 * Validates that the name is non-empty after trimming before calling the API.
 */
@Injectable({ providedIn: 'root' })
export class CreateAgentUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: CreateAgentInput): Observable<CreateAgentOutput> {
    const trimmed = input.name.trim();

    if (!trimmed) {
      return throwError(() => new InvalidAgentName('Agent name is required'));
    }

    return this.api.createAgent({ name: trimmed }).pipe(
      map((agent) => ({ agent })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to create agent');
        return throwError(() => new AgentCreationFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createCreateAgentUseCase(api?: AdminApiService): CreateAgentUseCase {
  return new CreateAgentUseCase(api);
}
