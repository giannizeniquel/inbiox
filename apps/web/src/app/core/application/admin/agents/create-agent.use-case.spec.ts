import { of, throwError } from 'rxjs';
import { CreateAgentUseCase, CreateAgentInput, CreateAgentOutput, InvalidAgentName, AgentCreationFailed } from './create-agent.use-case';
import { AdminApiService, AdminAgentDTO } from '../../../api/admin-api.service';

describe('CreateAgentUseCase', () => {
  let useCase: CreateAgentUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const mockAgent: AdminAgentDTO = {
    id: 1,
    name: 'Agent Bob',
    active: true,
    is_available: true,
    user_id: null,
    user_email: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['createAgent']);
    useCase = new CreateAgentUseCase(apiSpy);
  });

  it('should call api.createAgent with trimmed name', (done) => {
    apiSpy.createAgent.and.returnValue(of(mockAgent));
    useCase.execute({ name: '  Agent Bob  ' }).subscribe({
      next: () => {
        expect(apiSpy.createAgent).toHaveBeenCalledWith({ name: 'Agent Bob' });
        done();
      },
    });
  });

  it('should return CreateAgentOutput with the created agent', (done) => {
    apiSpy.createAgent.and.returnValue(of(mockAgent));
    useCase.execute({ name: 'Agent Bob' }).subscribe({
      next: (output: CreateAgentOutput) => {
        expect(output.agent).toEqual(mockAgent);
        done();
      },
    });
  });

  it('should throw InvalidAgentName when name is empty string', (done) => {
    useCase.execute({ name: '' }).subscribe({
      error: (err: InvalidAgentName) => {
        expect(err).toBeInstanceOf(InvalidAgentName);
        expect(err.message).toBe('Agent name is required');
        done();
      },
    });
  });

  it('should throw InvalidAgentName when name is only whitespace', (done) => {
    useCase.execute({ name: '   ' }).subscribe({
      error: (err: InvalidAgentName) => {
        expect(err).toBeInstanceOf(InvalidAgentName);
        expect(err.name).toBe('InvalidAgentName');
        done();
      },
    });
  });

  it('should throw AgentCreationFailed when api.createAgent errors', (done) => {
    apiSpy.createAgent.and.returnValue(throwError(() => new Error('Duplicate agent name')));
    useCase.execute({ name: 'Agent Bob' }).subscribe({
      error: (err: AgentCreationFailed) => {
        expect(err).toBeInstanceOf(AgentCreationFailed);
        expect(err.message).toBe('Duplicate agent name');
        done();
      },
    });
  });

  it('should accept a valid non-empty name', (done) => {
    apiSpy.createAgent.and.returnValue(of(mockAgent));
    useCase.execute({ name: 'ValidAgentName' }).subscribe({
      next: (output: CreateAgentOutput) => {
        expect(output.agent.name).toBe('Agent Bob');
        done();
      },
    });
  });
});
