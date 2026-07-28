import { of, throwError } from 'rxjs';
import { ReactivateUserUseCase, ReactivateUserInput, ReactivateUserOutput, UserReactivationFailed } from './reactivate-user.use-case';
import { AdminApiService, AdminUserDTO } from '../../../api/admin-api.service';

describe('ReactivateUserUseCase', () => {
  let useCase: ReactivateUserUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const mockUser: AdminUserDTO = {
    id: 4,
    email: 'reactivated@example.com',
    roles: ['ROLE_AGENT'],
    is_active: true,
    agent_id: null,
    agent_name: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-02T00:00:00Z',
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['updateUser']);
    useCase = new ReactivateUserUseCase(apiSpy);
  });

  it('should call api.updateUser with id and is_active=true', (done) => {
    apiSpy.updateUser.and.returnValue(of(mockUser));
    useCase.execute({ id: 4 }).subscribe({
      next: () => {
        expect(apiSpy.updateUser).toHaveBeenCalledWith(4, { is_active: true });
        done();
      },
    });
  });

  it('should return ReactivateUserOutput with the reactivated user id', (done) => {
    apiSpy.updateUser.and.returnValue(of(mockUser));
    useCase.execute({ id: 4 }).subscribe({
      next: (output: ReactivateUserOutput) => {
        expect(output.id).toBe(4);
        done();
      },
    });
  });

  it('should throw UserReactivationFailed when api.updateUser errors', (done) => {
    apiSpy.updateUser.and.returnValue(throwError(() => new Error('User not found')));
    useCase.execute({ id: 999 }).subscribe({
      error: (err: UserReactivationFailed) => {
        expect(err).toBeInstanceOf(UserReactivationFailed);
        expect(err.message).toBe('User not found');
        done();
      },
    });
  });
});
