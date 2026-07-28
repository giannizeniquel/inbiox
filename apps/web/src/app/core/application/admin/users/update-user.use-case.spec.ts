import { of, throwError } from 'rxjs';
import { UpdateUserUseCase, UpdateUserInput, UpdateUserOutput, UserUpdateFailed } from './update-user.use-case';
import { AdminApiService, AdminUserDTO } from '../../../api/admin-api.service';

describe('UpdateUserUseCase', () => {
  let useCase: UpdateUserUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const mockUser: AdminUserDTO = {
    id: 5,
    email: 'updated@example.com',
    roles: ['ROLE_AGENT', 'ROLE_SUPERVISOR'],
    is_active: true,
    agent_id: null,
    agent_name: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-02T00:00:00Z',
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['updateUser']);
    useCase = new UpdateUserUseCase(apiSpy);
  });

  it('should call api.updateUser with id and email always included', (done) => {
    apiSpy.updateUser.and.returnValue(of(mockUser));
    useCase.execute({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENT'] }).subscribe({
      next: () => {
        expect(apiSpy.updateUser).toHaveBeenCalledWith(5, {
          email: 'updated@example.com',
          roles: ['ROLE_AGENT'],
        });
        done();
      },
    });
  });

  it('should include password in payload only when non-empty', (done) => {
    apiSpy.updateUser.and.returnValue(of(mockUser));
    useCase.execute({ id: 5, email: 'updated@example.com', password: 'newpass123', roles: ['ROLE_AGENT'] }).subscribe({
      next: () => {
        expect(apiSpy.updateUser).toHaveBeenCalledWith(5, {
          email: 'updated@example.com',
          password: 'newpass123',
          roles: ['ROLE_AGENT'],
        });
        done();
      },
    });
  });

  it('should not include password when empty string', (done) => {
    apiSpy.updateUser.and.returnValue(of(mockUser));
    useCase.execute({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENT'] }).subscribe({
      next: () => {
        const call = apiSpy.updateUser.calls.mostRecent();
        expect(Object.keys(call.args[1])).not.toContain('password');
        done();
      },
    });
  });

  it('should default roles to ROLE_AGENT when empty array', (done) => {
    apiSpy.updateUser.and.returnValue(of(mockUser));
    useCase.execute({ id: 5, email: 'updated@example.com', password: '', roles: [] }).subscribe({
      next: () => {
        expect(apiSpy.updateUser).toHaveBeenCalledWith(5, {
          email: 'updated@example.com',
          roles: ['ROLE_AGENT'],
        });
        done();
      },
    });
  });

  it('should return UpdateUserOutput with the updated user', (done) => {
    apiSpy.updateUser.and.returnValue(of(mockUser));
    useCase.execute({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENT'] }).subscribe({
      next: (output: UpdateUserOutput) => {
        expect(output.user).toEqual(mockUser);
        done();
      },
    });
  });

  it('should throw UserUpdateFailed when api.updateUser errors with not-found', (done) => {
    apiSpy.updateUser.and.returnValue(throwError(() => new Error('User not found')));
    useCase.execute({ id: 999, email: 'ghost@example.com', password: '', roles: ['ROLE_AGENT'] }).subscribe({
      error: (err: UserUpdateFailed) => {
        expect(err).toBeInstanceOf(UserUpdateFailed);
        expect(err.message).toBe('User not found');
        done();
      },
    });
  });
});
