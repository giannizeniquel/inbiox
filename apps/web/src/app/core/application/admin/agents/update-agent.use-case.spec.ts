import { of, throwError } from 'rxjs';
import { UpdateAgentUseCase, UpdateAgentInput, UpdateAgentOutput, AgentUpdateFailed } from './update-agent.use-case';
import { AdminApiService, AdminAgentDTO } from '../../../api/admin-api.service';

describe('UpdateAgentUseCase', () => {
  let useCase: UpdateAgentUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const mockAgent: AdminAgentDTO = {
    id: 5,
    name: 'Updated Agent',
    active: true,
    is_available: true,
    user_id: null,
    user_email: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-02T00:00:00Z',
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['updateAgent']);
    useCase = new UpdateAgentUseCase(apiSpy);
  });

  it('should call api.updateAgent with id and trimmed name and active', (done) => {
    apiSpy.updateAgent.and.returnValue(of(mockAgent));
    useCase.execute({ id: 5, name: '  Updated Agent  ', active: true }).subscribe({
      next: () => {
        expect(apiSpy.updateAgent).toHaveBeenCalledWith(5, { name: 'Updated Agent', active: true });
        done();
      },
    });
  });

  it('should return UpdateAgentOutput with the updated agent', (done) => {
    apiSpy.updateAgent.and.returnValue(of(mockAgent));
    useCase.execute({ id: 5, name: 'Updated Agent', active: true }).subscribe({
      next: (output: UpdateAgentOutput) => {
        expect(output.agent).toEqual(mockAgent);
        done();
      },
    });
  });

  it('should send active=false when deactivating', (done) => {
    const deactivatedAgent = { ...mockAgent, active: false };
    apiSpy.updateAgent.and.returnValue(of(deactivatedAgent));
    useCase.execute({ id: 5, name: 'Updated Agent', active: false }).subscribe({
      next: () => {
        expect(apiSpy.updateAgent).toHaveBeenCalledWith(5, { name: 'Updated Agent', active: false });
        done();
      },
    });
  });

  it('should throw AgentUpdateFailed when api.updateAgent errors with not-found', (done) => {
    apiSpy.updateAgent.and.returnValue(throwError(() => new Error('Agent not found')));
    useCase.execute({ id: 999, name: 'Ghost', active: true }).subscribe({
      error: (err: AgentUpdateFailed) => {
        expect(err).toBeInstanceOf(AgentUpdateFailed);
        expect(err.message).toBe('Agent not found');
        done();
      },
    });
  });
});
