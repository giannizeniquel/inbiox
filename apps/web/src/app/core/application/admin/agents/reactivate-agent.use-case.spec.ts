import { of, throwError } from 'rxjs';
import { ReactivateAgentUseCase, ReactivateAgentInput, ReactivateAgentOutput, AgentReactivationFailed } from './reactivate-agent.use-case';
import { AdminApiService, AdminAgentDTO } from '../../../api/admin-api.service';

describe('ReactivateAgentUseCase', () => {
  let useCase: ReactivateAgentUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const mockAgent: AdminAgentDTO = {
    id: 3,
    name: 'Reactivated Agent',
    active: true,
    is_available: true,
    user_id: null,
    user_email: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-02T00:00:00Z',
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['updateAgent']);
    useCase = new ReactivateAgentUseCase(apiSpy);
  });

  it('should call api.updateAgent with id and active=true', (done) => {
    apiSpy.updateAgent.and.returnValue(of(mockAgent));
    useCase.execute({ id: 3 }).subscribe({
      next: () => {
        expect(apiSpy.updateAgent).toHaveBeenCalledWith(3, { active: true });
        done();
      },
    });
  });

  it('should return ReactivateAgentOutput with the reactivated agent id', (done) => {
    apiSpy.updateAgent.and.returnValue(of(mockAgent));
    useCase.execute({ id: 3 }).subscribe({
      next: (output: ReactivateAgentOutput) => {
        expect(output.id).toBe(3);
        done();
      },
    });
  });

  it('should throw AgentReactivationFailed when api.updateAgent errors', (done) => {
    apiSpy.updateAgent.and.returnValue(throwError(() => new Error('Agent not found')));
    useCase.execute({ id: 999 }).subscribe({
      error: (err: AgentReactivationFailed) => {
        expect(err).toBeInstanceOf(AgentReactivationFailed);
        expect(err.message).toBe('Agent not found');
        done();
      },
    });
  });
});
