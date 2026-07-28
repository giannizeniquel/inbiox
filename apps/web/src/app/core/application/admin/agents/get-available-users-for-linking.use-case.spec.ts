import { of, throwError } from 'rxjs';
import { GetAvailableUsersForLinkingUseCase, GetAvailableUsersForLinkingOutput, AvailableUsersFetchFailed } from './get-available-users-for-linking.use-case';
import { AdminApiService, AdminUserDTO, PaginatedResponse } from '../../../api/admin-api.service';

describe('GetAvailableUsersForLinkingUseCase', () => {
  let useCase: GetAvailableUsersForLinkingUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const userWithAgent: AdminUserDTO = {
    id: 1,
    email: 'already-linked@example.com',
    roles: ['ROLE_AGENT'],
    is_active: true,
    agent_id: 5,
    agent_name: 'Agent A',
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  };

  const availableUser: AdminUserDTO = {
    id: 2,
    email: 'available@example.com',
    roles: ['ROLE_AGENT'],
    is_active: true,
    agent_id: null,
    agent_name: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  };

  const inactiveUser: AdminUserDTO = {
    id: 3,
    email: 'inactive@example.com',
    roles: ['ROLE_AGENT'],
    is_active: false,
    agent_id: null,
    agent_name: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['listUsers']);
    useCase = new GetAvailableUsersForLinkingUseCase(apiSpy);
  });

  it('should call api.listUsers with page 1 and limit 200', (done) => {
    const response: PaginatedResponse<AdminUserDTO> = {
      data: [availableUser],
      meta: { page: 1, limit: 200, total: 1, has_next: false },
    };
    apiSpy.listUsers.and.returnValue(of(response));
    useCase.execute({}).subscribe({
      next: () => {
        expect(apiSpy.listUsers).toHaveBeenCalledWith(1, 200);
        done();
      },
    });
  });

  it('should filter to active users without agent_id', (done) => {
    const response: PaginatedResponse<AdminUserDTO> = {
      data: [userWithAgent, availableUser, inactiveUser],
      meta: { page: 1, limit: 200, total: 3, has_next: false },
    };
    apiSpy.listUsers.and.returnValue(of(response));
    useCase.execute({}).subscribe({
      next: (output: GetAvailableUsersForLinkingOutput) => {
        expect(output.users).toHaveSize(1);
        expect(output.users[0].id).toBe(2);
        expect(output.users[0].email).toBe('available@example.com');
        done();
      },
    });
  });

  it('should return empty array when all users are already linked', (done) => {
    const response: PaginatedResponse<AdminUserDTO> = {
      data: [userWithAgent],
      meta: { page: 1, limit: 200, total: 1, has_next: false },
    };
    apiSpy.listUsers.and.returnValue(of(response));
    useCase.execute({}).subscribe({
      next: (output: GetAvailableUsersForLinkingOutput) => {
        expect(output.users).toHaveSize(0);
        done();
      },
    });
  });

  it('should throw AvailableUsersFetchFailed when api.listUsers errors', (done) => {
    apiSpy.listUsers.and.returnValue(throwError(() => new Error('Network error')));
    useCase.execute({}).subscribe({
      error: (err: AvailableUsersFetchFailed) => {
        expect(err).toBeInstanceOf(AvailableUsersFetchFailed);
        expect(err.message).toBe('Network error');
        done();
      },
    });
  });
});
