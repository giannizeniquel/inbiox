import { of, throwError } from 'rxjs';
import { ListAgentsUseCase, ListAgentsInput, ListAgentsOutput, AgentListFailed } from './list-agents.use-case';
import { AdminApiService, AdminAgentDTO, PaginatedResponse } from './admin-api.service';

describe('ListAgentsUseCase', () => {
  let useCase: ListAgentsUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const mockAgent: AdminAgentDTO = {
    id: 1,
    name: 'Agent Alice',
    active: true,
    is_available: true,
    timezone: 'UTC',
    user_id: 10,
    user_email: 'alice@example.com',
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  };

  const paginatedResponse: PaginatedResponse<AdminAgentDTO> = {
    data: [mockAgent],
    meta: { page: 1, limit: 20, total: 1, has_next: false },
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['listAgents']);
    useCase = new ListAgentsUseCase(apiSpy);
  });

  it('should call api.listAgents with page and limit', (done) => {
    apiSpy.listAgents.and.returnValue(of(paginatedResponse));
    useCase.execute({ page: 1, limit: 20 }).subscribe({
      next: () => {
        expect(apiSpy.listAgents).toHaveBeenCalledWith(1, 20);
        done();
      },
    });
  });

  it('should map response to ListAgentsOutput with computed totalPages', (done) => {
    const response: PaginatedResponse<AdminAgentDTO> = {
      data: [mockAgent],
      meta: { page: 1, limit: 20, total: 45, has_next: true },
    };
    apiSpy.listAgents.and.returnValue(of(response));
    useCase.execute({ page: 1 }).subscribe({
      next: (output: ListAgentsOutput) => {
        expect(output.agents).toEqual([mockAgent]);
        expect(output.total).toBe(45);
        expect(output.totalPages).toBe(3); // ceil(45/20) = 3
        done();
      },
    });
  });

  it('should default limit to undefined when not provided', (done) => {
    apiSpy.listAgents.and.returnValue(of(paginatedResponse));
    useCase.execute({ page: 2 }).subscribe({
      next: () => {
        expect(apiSpy.listAgents).toHaveBeenCalledWith(2, undefined);
        done();
      },
    });
  });

  it('should return totalPages of 1 when total is 0', (done) => {
    const emptyResponse: PaginatedResponse<AdminAgentDTO> = {
      data: [],
      meta: { page: 1, limit: 20, total: 0, has_next: false },
    };
    apiSpy.listAgents.and.returnValue(of(emptyResponse));
    useCase.execute({ page: 1 }).subscribe({
      next: (output: ListAgentsOutput) => {
        expect(output.totalPages).toBe(1);
        expect(output.total).toBe(0);
        expect(output.agents).toEqual([]);
        done();
      },
    });
  });

  it('should throw AgentListFailed when api.listAgents errors', (done) => {
    apiSpy.listAgents.and.returnValue(throwError(() => new Error('Network error')));
    useCase.execute({ page: 1 }).subscribe({
      error: (err: AgentListFailed) => {
        expect(err).toBeInstanceOf(AgentListFailed);
        expect(err.message).toBe('Network error');
        done();
      },
    });
  });
});
