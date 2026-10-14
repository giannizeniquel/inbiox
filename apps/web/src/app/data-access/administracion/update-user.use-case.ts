import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService, AdminUserDTO, UpdateUserPayload } from './admin-api.service';
import { extractApiError } from '../../core/http/extract-api-error';

/**
 * UpdateUserInput — parameters for updating an existing user.
 */
export interface UpdateUserInput {
  id: number;
  email: string;
  password: string;
  roles: string[];
}

/**
 * UpdateUserOutput — the updated user.
 */
export interface UpdateUserOutput {
  user: AdminUserDTO;
}

/**
 * UserUpdateFailed — exception thrown when the user update API call fails.
 */
export class UserUpdateFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'UserUpdateFailed';
  }
}

/**
 * UpdateUserUseCase — updates an existing user with payload diff.
 *
 * Builds the API payload:
 * - email is always sent
 * - password is only included if non-empty (empty = keep current)
 * - roles default to ['ROLE_AGENT'] if the provided array is empty
 *
 * Role toggling is handled by the component as pure form state.
 */
@Injectable({ providedIn: 'root' })
export class UpdateUserUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: UpdateUserInput): Observable<UpdateUserOutput> {
    const roles = input.roles.length > 0 ? input.roles : ['ROLE_AGENT'];

    const payload: UpdateUserPayload = {
      email: input.email,
      roles,
    };

    if (input.password) {
      payload.password = input.password;
    }

    return this.api.updateUser(input.id, payload).pipe(
      map((user) => ({ user })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to update user');
        return throwError(() => new UserUpdateFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createUpdateUserUseCase(api?: AdminApiService): UpdateUserUseCase {
  return new UpdateUserUseCase(api);
}
