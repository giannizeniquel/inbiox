import { of, throwError } from 'rxjs';
import { ListUsersUseCase, ListUsersInput, ListUsersOutput, UserListFailed } from './list-users.use-case';
import { AdminApiService, AdminUserDTO, PaginatedResponse } from './admin-api.service';

describe('ListUsersUseCase', () => {
  let useCase: ListUsersUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const mockUser: AdminUserDTO = {
    id: 1,
    email: 'user@example.com',
    roles: ['ROLE_AGENT'],
    is_active: true,
    agent_id: 3,
    agent_name: 'Agent A',
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  };

  const paginatedResponse: PaginatedResponse<AdminUserDTO> = {
    data: [mockUser],
    meta: { page: 1, limit: 20, total: 1, has_next: false },
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['listUsers']);
    useCase = new ListUsersUseCase(apiSpy);
  });

  it('should call api.listUsers with page and limit', (done) => {
    apiSpy.listUsers.and.returnValue(of(paginatedResponse));
    useCase.execute({ page: 1, limit: 20 }).subscribe({
      next: () => {
        expect(apiSpy.listUsers).toHaveBeenCalledWith(1, 20);
        done();
      },
    });
  });

  it('should map response to ListUsersOutput with computed totalPages', (done) => {
    const response: PaginatedResponse<AdminUserDTO> = {
      data: [mockUser],
      meta: { page: 2, limit: 20, total: 55, has_next: true },
    };
    apiSpy.listUsers.and.returnValue(of(response));
    useCase.execute({ page: 2 }).subscribe({
      next: (output: ListUsersOutput) => {
        expect(output.users).toEqual([mockUser]);
        expect(output.total).toBe(55);
        expect(output.totalPages).toBe(3); // ceil(55/20) = 3
        done();
      },
    });
  });

  it('should default limit to undefined when not provided', (done) => {
    apiSpy.listUsers.and.returnValue(of(paginatedResponse));
    useCase.execute({ page: 3 }).subscribe({
      next: () => {
        expect(apiSpy.listUsers).toHaveBeenCalledWith(3, undefined);
        done();
      },
    });
  });

  it('should return totalPages of 1 when total is 0', (done) => {
    const emptyResponse: PaginatedResponse<AdminUserDTO> = {
      data: [],
      meta: { page: 1, limit: 20, total: 0, has_next: false },
    };
    apiSpy.listUsers.and.returnValue(of(emptyResponse));
    useCase.execute({ page: 1 }).subscribe({
      next: (output: ListUsersOutput) => {
        expect(output.totalPages).toBe(1);
        expect(output.total).toBe(0);
        expect(output.users).toEqual([]);
        done();
      },
    });
  });

  it('should throw UserListFailed when api.listUsers errors', (done) => {
    apiSpy.listUsers.and.returnValue(throwError(() => new Error('Network error')));
    useCase.execute({ page: 1 }).subscribe({
      error: (err: UserListFailed) => {
        expect(err).toBeInstanceOf(UserListFailed);
        expect(err.message).toBe('Network error');
        done();
      },
    });
  });
});
