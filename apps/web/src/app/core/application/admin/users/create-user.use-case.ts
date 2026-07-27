import { Injectable, inject } from '@angular/core';
import { Observable, throwError, of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { AdminApiService, AdminAgentDTO, AdminUserDTO } from '../../../api/admin-api.service';
import { extractApiError } from '../../shared/extract-api-error';

/**
 * CreateUserInput — parameters for creating a new user.
 */
export interface CreateUserInput {
  email: string;
  password: string;
  roles: string[];
  agentId: number | null;
}

/**
 * CreateUserOutput — the newly created user.
 */
export interface CreateUserOutput {
  user: AdminUserDTO;
}

/**
 * InvalidUserCredentials — exception thrown when email or password is empty.
 */
export class InvalidUserCredentials extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'InvalidUserCredentials';
  }
}

/**
 * UserCreationFailed — exception thrown when the user creation API call fails.
 */
export class UserCreationFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'UserCreationFailed';
  }
}

/**
 * CreateUserUseCase — creates a new user and provides available agents for linking.
 *
 * Has two methods:
 * - execute(): validates email + password, calls the create API
 * - loadAvailableAgents(): fetches agents eligible for linking (active + no user assigned)
 */
@Injectable({ providedIn: 'root' })
export class CreateUserUseCase {
  constructor(private readonly api: AdminApiService = inject(AdminApiService)) {}

  /**
   * Create a new user.
   *
   * Validates that email and password are non-empty, defaults roles to ['ROLE_AGENT']
   * if the provided roles array is empty, then calls the API.
   */
  execute(input: CreateUserInput): Observable<CreateUserOutput> {
    if (!input.email || !input.password) {
      return throwError(() => new InvalidUserCredentials('Email and password are required'));
    }

    const roles = input.roles.length > 0 ? input.roles : ['ROLE_AGENT'];

    return this.api.createUser({
      email: input.email,
      password: input.password,
      roles,
      agent_id: input.agentId,
    }).pipe(
      map((user) => ({ user })),
      catchError((err) => {
        const message = extractApiError(err, 'Failed to create user');
        return throwError(() => new UserCreationFailed(message, err));
      }),
    );
  }

  /**
   * Load available agents for the user creation dropdown.
   *
   * Fetches all agents and filters to those that are active and not already
   * linked to a user. Silently returns empty array on error — agents are
   * optional for user creation.
   */
  loadAvailableAgents(): Observable<AdminAgentDTO[]> {
    return this.api.listAgents(1, 100).pipe(
      map((res) => res.data.filter((a) => a.active && !a.user_id)),
      catchError(() => {
        // Silently fail — agents list is optional for user creation
        return of([]);
      }),
    );
  }
}

/**
 * Factory function for manual instantiation (testing).
 */
export function createCreateUserUseCase(api?: AdminApiService): CreateUserUseCase {
  return new CreateUserUseCase(api);
}
