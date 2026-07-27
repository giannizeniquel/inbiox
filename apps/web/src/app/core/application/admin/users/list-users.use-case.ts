import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService, AdminUserDTO } from '../../../api/admin-api.service';
import { extractApiError } from '../../shared/extract-api-error';

/**
 * ListUsersInput — pagination parameters for user listing.
 */
export interface ListUsersInput {
  page: number;
  limit?: number;
}

/**
 * ListUsersOutput — paginated user list response.
 */
export interface ListUsersOutput {
  users: AdminUserDTO[];
  totalPages: number;
  total: number;
}

/**
 * UserListFailed — exception thrown when the user list API call fails.
 */
export class UserListFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'UserListFailed';
  }
}

/**
 * ListUsersUseCase — retrieves a paginated list of users.
 *
 * Delegates to AdminApiService.listUsers and maps the paginated response
 * into a flat output with computed totalPages.
 */
@Injectable({ providedIn: 'root' })
export class ListUsersUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  execute(input: ListUsersInput): Observable<ListUsersOutput> {
    return this.api.listUsers(input.page, input.limit).pipe(
      map((res) => ({
        users: res.data,
        totalPages: Math.max(1, Math.ceil(res.meta.total / res.meta.limit)),
        total: res.meta.total,
      })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to load users');
        return throwError(() => new UserListFailed(message, err));
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createListUsersUseCase(api?: AdminApiService): ListUsersUseCase {
  return new ListUsersUseCase(api);
}
