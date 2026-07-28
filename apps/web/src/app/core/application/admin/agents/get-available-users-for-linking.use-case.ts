import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService, AdminUserDTO } from '../../../api/admin-api.service';
import { extractApiError } from '../../shared/extract-api-error';

/**
 * GetAvailableUsersForLinkingInput — no input needed (fixed params: page 1, limit 200).
 */
// eslint-disable-next-line @typescript-eslint/no-empty-object-type
export interface GetAvailableUsersForLinkingInput {}

/**
 * GetAvailableUsersForLinkingOutput — list of users available for linking.
 */
export interface GetAvailableUsersForLinkingOutput {
  users: AdminUserDTO[];
}

/**
 * AvailableUsersFetchFailed — exception thrown when the users fetch for linking fails.
 */
export class AvailableUsersFetchFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'AvailableUsersFetchFailed';
  }
}

/**
 * GetAvailableUsersForLinkingUseCase — fetches users eligible for agent linking.
 *
 * Calls listUsers(1, 200) and filters to only active users not already linked to an agent.
 */
@Injectable({ providedIn: 'root' })
export class GetAvailableUsersForLinkingUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(_input: GetAvailableUsersForLinkingInput): Observable<GetAvailableUsersForLinkingOutput> {
    return this.api.listUsers(1, 200).pipe(
      map((res) => ({
        users: res.data.filter((u) => u.is_active && !u.agent_id),
      })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to load users for linking');
        return throwError(() => new AvailableUsersFetchFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createGetAvailableUsersForLinkingUseCase(
  api?: AdminApiService,
): GetAvailableUsersForLinkingUseCase {
  return new GetAvailableUsersForLinkingUseCase(api);
}
