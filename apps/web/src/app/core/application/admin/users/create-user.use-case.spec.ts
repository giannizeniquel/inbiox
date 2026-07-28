import { of, throwError } from 'rxjs';
import { CreateUserUseCase, CreateUserInput, CreateUserOutput, InvalidUserCredentials, UserCreationFailed } from './create-user.use-case';
import { AdminApiService, AdminAgentDTO, AdminUserDTO } from '../../../api/admin-api.service';

describe('CreateUserUseCase', () => {
  let useCase: CreateUserUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const mockUser: AdminUserDTO = {
    id: 10,
    email: 'newuser@example.com',
    roles: ['ROLE_AGENT'],
    is_active: true,
    agent_id: null,
    agent_name: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['createUser', 'listAgents']);
    useCase = new CreateUserUseCase(apiSpy);
  });

  describe('execute()', () => {
    it('should call api.createUser with email, password, roles, and agentId', (done) => {
      apiSpy.createUser.and.returnValue(of(mockUser));
      useCase.execute({ email: 'newuser@example.com', password: 'secret123', roles: ['ROLE_AGENT'], agentId: null }).subscribe({
        next: () => {
          expect(apiSpy.createUser).toHaveBeenCalledWith({
            email: 'newuser@example.com',
            password: 'secret123',
            roles: ['ROLE_AGENT'],
            agent_id: null,
          });
          done();
        },
      });
    });

    it('should default roles to ROLE_AGENT when empty array is provided', (done) => {
      apiSpy.createUser.and.returnValue(of(mockUser));
      useCase.execute({ email: 'newuser@example.com', password: 'secret123', roles: [], agentId: 5 }).subscribe({
        next: () => {
          expect(apiSpy.createUser).toHaveBeenCalledWith({
            email: 'newuser@example.com',
            password: 'secret123',
            roles: ['ROLE_AGENT'],
            agent_id: 5,
          });
          done();
        },
      });
    });

    it('should return CreateUserOutput with the created user', (done) => {
      apiSpy.createUser.and.returnValue(of(mockUser));
      useCase.execute({ email: 'newuser@example.com', password: 'secret123', roles: ['ROLE_AGENT'], agentId: null }).subscribe({
        next: (output: CreateUserOutput) => {
          expect(output.user).toEqual(mockUser);
          done();
        },
      });
    });

    it('should throw InvalidUserCredentials when email is empty', (done) => {
      useCase.execute({ email: '', password: 'secret123', roles: ['ROLE_AGENT'], agentId: null }).subscribe({
        error: (err: InvalidUserCredentials) => {
          expect(err).toBeInstanceOf(InvalidUserCredentials);
          expect(err.message).toBe('Email and password are required');
          done();
        },
      });
    });

    it('should throw InvalidUserCredentials when password is empty', (done) => {
      useCase.execute({ email: 'user@example.com', password: '', roles: ['ROLE_AGENT'], agentId: null }).subscribe({
        error: (err: InvalidUserCredentials) => {
          expect(err).toBeInstanceOf(InvalidUserCredentials);
          expect(err.name).toBe('InvalidUserCredentials');
          done();
        },
      });
    });

    it('should throw UserCreationFailed when api.createUser errors with duplicate email', (done) => {
      apiSpy.createUser.and.returnValue(throwError(() => new Error('Email already exists')));
      useCase.execute({ email: 'existing@example.com', password: 'secret123', roles: ['ROLE_AGENT'], agentId: null }).subscribe({
        error: (err: UserCreationFailed) => {
          expect(err).toBeInstanceOf(UserCreationFailed);
          expect(err.message).toBe('Email already exists');
          done();
        },
      });
    });
  });
});
