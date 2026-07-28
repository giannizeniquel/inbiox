import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService } from '../../../api/admin-api.service';
import { extractApiError } from '../../shared/extract-api-error';

/**
 * DeactivateUserInput — the ID of the user to deactivate.
 */
export interface DeactivateUserInput {
  id: number;
}

/**
 * DeactivateUserOutput — the ID of the deactivated user.
 */
export interface DeactivateUserOutput {
  id: number;
}

/**
 * UserDeactivationFailed — exception thrown when the user deactivation API call fails.
 */
export class UserDeactivationFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'UserDeactivationFailed';
  }
}

/**
 * DeactivateUserUseCase — soft-deletes a user by calling the delete endpoint.
 *
 * The backend performs a soft delete (is_active = false). This use case wraps
 * AdminApiService.deleteUser and returns the user ID on success.
 */
@Injectable({ providedIn: 'root' })
export class DeactivateUserUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: DeactivateUserInput): Observable<DeactivateUserOutput> {
    return this.api.deleteUser(input.id).pipe(
      map(() => ({ id: input.id })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to deactivate user');
        return throwError(() => new UserDeactivationFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createDeactivateUserUseCase(api?: AdminApiService): DeactivateUserUseCase {
  return new DeactivateUserUseCase(api);
}
