import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { AdminApiService, LinkUserResponse } from './admin-api.service';
import { extractApiError } from '../../core/http/extract-api-error';

/**
 * LinkUserInput — parameters for linking a user to an agent.
 */
export interface LinkUserInput {
  agentId: number;
  userId: number;
}

/**
 * UnlinkUserInput — parameters for unlinking a user from an agent.
 */
export interface UnlinkUserInput {
  agentId: number;
}

/**
 * AgentLinkingFailed — exception thrown when a link or unlink API call fails.
 */
export class AgentLinkingFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'AgentLinkingFailed';
  }
}

/**
 * ManageAgentUserLinkingUseCase — manages linking and unlinking users to agents.
 *
 * Groups link/unlink because the UI always treats them as related actions
 * on the same agent. Keeping them in one class makes the dependency surface
 * obvious and matches the spec.
 *
 * This use case does NOT have an execute() method — callers use link() or unlink()
 * directly since these are independently invoked from different UI flows.
 */
@Injectable({ providedIn: 'root' })
export class ManageAgentUserLinkingUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  /**
   * Link a user to an agent.
   *
   * @param input - The agent ID and user ID to link
   * @returns Observable with the link response (id, user_id, user_email)
   * @throws AgentLinkingFailed if the API call fails
   */
  link(input: LinkUserInput): Observable<LinkUserResponse> {
    return this.api.linkAgentToUser(input.agentId, { user_id: input.userId }).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'Failed to link user');
        return throwError(() => new AgentLinkingFailed(message, err));
      }),
    );
  }

  /**
   * Unlink a user from an agent.
   *
   * @param input - The agent ID to unlink
   * @returns Observable that completes on success
   * @throws AgentLinkingFailed if the API call fails
   */
  unlink(input: UnlinkUserInput): Observable<void> {
    return this.api.unlinkAgentFromUser(input.agentId).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'Failed to unlink user');
        return throwError(() => new AgentLinkingFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createManageAgentUserLinkingUseCase(
  api?: AdminApiService,
): ManageAgentUserLinkingUseCase {
  return new ManageAgentUserLinkingUseCase(api);
}
