import { of, throwError } from 'rxjs';
import { DeactivateAgentUseCase, DeactivateAgentInput, DeactivateAgentOutput, AgentDeactivationFailed } from './deactivate-agent.use-case';
import { AdminApiService } from '../../../api/admin-api.service';

describe('DeactivateAgentUseCase', () => {
  let useCase: DeactivateAgentUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['deleteAgent']);
    useCase = new DeactivateAgentUseCase(apiSpy);
  });

  it('should call api.deleteAgent with the agent id', (done) => {
    apiSpy.deleteAgent.and.returnValue(of(undefined));
    useCase.execute({ id: 7 }).subscribe({
      next: () => {
        expect(apiSpy.deleteAgent).toHaveBeenCalledWith(7);
        done();
      },
    });
  });

  it('should return DeactivateAgentOutput with the deactivated agent id', (done) => {
    apiSpy.deleteAgent.and.returnValue(of(undefined));
    useCase.execute({ id: 7 }).subscribe({
      next: (output: DeactivateAgentOutput) => {
        expect(output.id).toBe(7);
        done();
      },
    });
  });

  it('should throw AgentDeactivationFailed when api.deleteAgent errors', (done) => {
    apiSpy.deleteAgent.and.returnValue(throwError(() => new Error('Network error')));
    useCase.execute({ id: 7 }).subscribe({
      error: (err: AgentDeactivationFailed) => {
        expect(err).toBeInstanceOf(AgentDeactivationFailed);
        expect(err.message).toBe('Network error');
        done();
      },
    });
  });
});
