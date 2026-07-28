import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService } from '../../../api/admin-api.service';
import { extractApiError } from '../../shared/extract-api-error';

/**
 * ReactivateUserInput — the ID of the user to reactivate.
 */
export interface ReactivateUserInput {
  id: number;
}

/**
 * ReactivateUserOutput — the ID of the reactivated user.
 */
export interface ReactivateUserOutput {
  id: number;
}

/**
 * UserReactivationFailed — exception thrown when the user reactivation API call fails.
 */
export class UserReactivationFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'UserReactivationFailed';
  }
}

/**
 * ReactivateUserUseCase — reactivates a previously deactivated user.
 *
 * Calls AdminApiService.updateUser with { is_active: true } to re-enable the user.
 */
@Injectable({ providedIn: 'root' })
export class ReactivateUserUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: ReactivateUserInput): Observable<ReactivateUserOutput> {
    return this.api.updateUser(input.id, { is_active: true }).pipe(
      map(() => ({ id: input.id })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to reactivate user');
        return throwError(() => new UserReactivationFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createReactivateUserUseCase(api?: AdminApiService): ReactivateUserUseCase {
  return new ReactivateUserUseCase(api);
}
