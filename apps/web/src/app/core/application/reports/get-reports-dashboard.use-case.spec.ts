import { of, throwError } from 'rxjs';
import { GetReportsDashboardUseCase, GetReportsDashboardInput, ReportsDashboardData, ReportsFetchFailed } from './get-reports-dashboard.use-case';
import { ReportsApiService } from '../../api/reports-api.service';

describe('GetReportsDashboardUseCase', () => {
  let useCase: GetReportsDashboardUseCase;
  let apiSpy: jasmine.SpyObj<ReportsApiService>;

  const input: GetReportsDashboardInput = { params: { fromDate: '2024-01-01', toDate: '2024-01-31' } };

  const mockFrt = { avg_frt_minutes: 5.5 };
  const mockRt = { avg_rt_minutes: 42.3 };
  const mockCounts = { new: 10, active: 5, waiting: 3, resolved: 20, closed: 8 };
  const mockVolume = {
    data: [
      { date: '2024-01-01', tickets_created: 5 },
      { date: '2024-01-02', tickets_created: 8 },
    ],
    total: 13,
    trend: 10,
  };
  const mockAgents = {
    agents: [
      { agent_id: 1, tickets_assigned: 10, avg_frt_minutes: 3.2, tickets_resolved: 8 },
      { agent_id: 2, tickets_assigned: 5, avg_frt_minutes: 6.1, tickets_resolved: 4 },
    ],
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('ReportsApiService', [
      'getFirstResponseTime',
      'getResolutionTime',
      'getTicketCounts',
      'getVolumeTrend',
      'getAgentMetrics',
    ]);
    useCase = new GetReportsDashboardUseCase(apiSpy);
  });

  it('should call all 5 API methods with params', (done) => {
    apiSpy.getFirstResponseTime.and.returnValue(of(mockFrt));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getTicketCounts.and.returnValue(of(mockCounts));
    apiSpy.getVolumeTrend.and.returnValue(of(mockVolume));
    apiSpy.getAgentMetrics.and.returnValue(of(mockAgents));

    useCase.execute(input).subscribe({
      next: () => {
        expect(apiSpy.getFirstResponseTime).toHaveBeenCalledWith(input.params);
        expect(apiSpy.getResolutionTime).toHaveBeenCalledWith(input.params);
        expect(apiSpy.getTicketCounts).toHaveBeenCalledWith(input.params);
        expect(apiSpy.getVolumeTrend).toHaveBeenCalledWith(input.params);
        expect(apiSpy.getAgentMetrics).toHaveBeenCalledWith(input.params);
        done();
      },
    });
  });

  it('should map responses to ReportsDashboardData shape on success', (done) => {
    apiSpy.getFirstResponseTime.and.returnValue(of(mockFrt));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getTicketCounts.and.returnValue(of(mockCounts));
    apiSpy.getVolumeTrend.and.returnValue(of(mockVolume));
    apiSpy.getAgentMetrics.and.returnValue(of(mockAgents));

    useCase.execute(input).subscribe({
      next: (data: ReportsDashboardData) => {
        expect(data.frt.value).toBe(5.5);
        expect(data.rt.value).toBe(42.3);
        // counts: open = new + active + waiting = 10+5+3=18; closed = resolved + closed = 20+8=28
        expect(data.counts.open).toBe(18);
        expect(data.counts.closed).toBe(28);
        expect(data.volume).toEqual([
          { date: '2024-01-01', count: 5 },
          { date: '2024-01-02', count: 8 },
        ]);
        expect(data.agents).toHaveSize(2);
        expect(data.agents[0].agentId).toBe('1');
        expect(data.agents[0].assigned).toBe(10);
        done();
      },
    });
  });

  it('should compute loadPercentage as tickets_assigned * 20 capped at 100', (done) => {
    apiSpy.getFirstResponseTime.and.returnValue(of(mockFrt));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getTicketCounts.and.returnValue(of(mockCounts));
    apiSpy.getVolumeTrend.and.returnValue(of(mockVolume));
    apiSpy.getAgentMetrics.and.returnValue(of(mockAgents));

    useCase.execute(input).subscribe({
      next: (data: ReportsDashboardData) => {
        // agent 1: 10 * 20 = 200 → capped at 100
        expect(data.agents[0].loadPercentage).toBe(100);
        // agent 2: 5 * 20 = 100
        expect(data.agents[1].loadPercentage).toBe(100);
        done();
      },
    });
  });

  it('should use defaults for failed streams (individual catchError)', (done) => {
    // FRT stream fails — should default to 0
    apiSpy.getFirstResponseTime.and.returnValue(throwError(() => new Error('FRT error')));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getTicketCounts.and.returnValue(of(mockCounts));
    apiSpy.getVolumeTrend.and.returnValue(of(mockVolume));
    apiSpy.getAgentMetrics.and.returnValue(of(mockAgents));

    useCase.execute(input).subscribe({
      next: (data: ReportsDashboardData) => {
        expect(data.frt.value).toBe(0);
        expect(data.rt.value).toBe(42.3); // still works
        expect(data.counts.open).toBe(18); // still works
        done();
      },
    });
  });

  it('should return empty arrays for volume and agents when those streams fail', (done) => {
    apiSpy.getFirstResponseTime.and.returnValue(of(mockFrt));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getTicketCounts.and.returnValue(of(mockCounts));
    apiSpy.getVolumeTrend.and.returnValue(throwError(() => new Error('Volume error')));
    apiSpy.getAgentMetrics.and.returnValue(throwError(() => new Error('Agents error')));

    useCase.execute(input).subscribe({
      next: (data: ReportsDashboardData) => {
        expect(data.volume).toEqual([]);
        expect(data.agents).toEqual([]);
        expect(data.frt.value).toBe(5.5); // still works
        done();
      },
    });
  });

  // Note: The outer ReportsFetchFailed is a safety net for truly unexpected forkJoin
  // structural errors. Since every stream has its own catchError, individual stream
  // failures are swallowed and result in default values — the outer handler only
  // fires for unexpected exceptions thrown during forkJoin execution itself.
  // The individual catchError tests above (FRT failure, volume/agents failure)
  // already prove the resilience strategy works.
});
