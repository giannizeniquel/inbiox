import { of, throwError } from 'rxjs';
import { GetReportsDashboardUseCase, GetReportsDashboardInput, ReportsDashboardData, ReportsFetchFailed } from './get-reports-dashboard.use-case';
import { ReporteApiService } from './reporte-api.service';

describe('GetReportsDashboardUseCase', () => {
  let useCase: GetReportsDashboardUseCase;
  let apiSpy: jasmine.SpyObj<ReporteApiService>;

  const input: GetReportsDashboardInput = { params: { fromDate: '2024-01-01', toDate: '2024-01-31' } };

  const mockFrt = { frtPromedioMinutos: 5.5 };
  const mockRt = { rtPromedioMinutos: 42.3 };
  const mockConteos = { nuevo: 10, activo: 5, en_espera: 3, resuelto: 20, cerrado: 8 };
  const mockVolumen = {
    data: [
      { fecha: '2024-01-01', ticketsCreados: 5 },
      { fecha: '2024-01-02', ticketsCreados: 8 },
    ],
    total: 13,
    trend: 10,
  };
  const mockAgentes = {
    agents: [
      { agenteId: 1, ticketsAsignados: 10, frtPromedioMinutos: 3.2, ticketsResueltos: 8 },
      { agenteId: 2, ticketsAsignados: 5, frtPromedioMinutos: 6.1, ticketsResueltos: 4 },
    ],
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('ReporteApiService', [
      'getFirstResponseTime',
      'getResolutionTime',
      'getConteosDeTickets',
      'getTendenciaDeVolumen',
      'getMetricasDeAgente',
      'getCargaDeAgente',
      'getSinAsignar',
      'getTendenciaDeFrt',
      'getDemoraDeRespuesta',
      'getAgentesDisponibles',
      'getMapaDeCalor',
      'getContactosRecurrentes',
      'getReabiertos',
    ]);
    useCase = new GetReportsDashboardUseCase(apiSpy);
  });

  /** Wires up every stream past Group A so a full execute() completes. */
  function mockGroupsBAndC(): void {
    apiSpy.getCargaDeAgente.and.returnValue(of([]));
    apiSpy.getSinAsignar.and.returnValue(of({ cantidad: 0, tickets: [] }));
    apiSpy.getTendenciaDeFrt.and.returnValue(of([]));
    apiSpy.getAgentesDisponibles.and.returnValue(of([]));
    apiSpy.getMapaDeCalor.and.returnValue(of([]));
    apiSpy.getContactosRecurrentes.and.returnValue(of([]));
    apiSpy.getReabiertos.and.returnValue(of([]));
  }

  it('should call all 5 API methods with params', (done) => {
    apiSpy.getFirstResponseTime.and.returnValue(of(mockFrt));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getConteosDeTickets.and.returnValue(of(mockConteos));
    apiSpy.getTendenciaDeVolumen.and.returnValue(of(mockVolumen));
    apiSpy.getMetricasDeAgente.and.returnValue(of(mockAgentes));

    useCase.execute(input).subscribe({
      next: () => {
        expect(apiSpy.getFirstResponseTime).toHaveBeenCalledWith(input.params);
        expect(apiSpy.getResolutionTime).toHaveBeenCalledWith(input.params);
        expect(apiSpy.getConteosDeTickets).toHaveBeenCalledWith(input.params);
        expect(apiSpy.getTendenciaDeVolumen).toHaveBeenCalledWith(input.params);
        expect(apiSpy.getMetricasDeAgente).toHaveBeenCalledWith(input.params);
        done();
      },
    });
  });

  it('should map responses to ReportsDashboardData shape on success', (done) => {
    apiSpy.getFirstResponseTime.and.returnValue(of(mockFrt));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getConteosDeTickets.and.returnValue(of(mockConteos));
    apiSpy.getTendenciaDeVolumen.and.returnValue(of(mockVolumen));
    apiSpy.getMetricasDeAgente.and.returnValue(of(mockAgentes));

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
    apiSpy.getConteosDeTickets.and.returnValue(of(mockConteos));
    apiSpy.getTendenciaDeVolumen.and.returnValue(of(mockVolumen));
    apiSpy.getMetricasDeAgente.and.returnValue(of(mockAgentes));

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
    apiSpy.getConteosDeTickets.and.returnValue(of(mockConteos));
    apiSpy.getTendenciaDeVolumen.and.returnValue(of(mockVolumen));
    apiSpy.getMetricasDeAgente.and.returnValue(of(mockAgentes));

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
    apiSpy.getConteosDeTickets.and.returnValue(of(mockConteos));
    apiSpy.getTendenciaDeVolumen.and.returnValue(throwError(() => new Error('Volume error')));
    apiSpy.getMetricasDeAgente.and.returnValue(throwError(() => new Error('Agents error')));

    useCase.execute(input).subscribe({
      next: (data: ReportsDashboardData) => {
        expect(data.volume).toEqual([]);
        expect(data.agents).toEqual([]);
        expect(data.frt.value).toBe(5.5); // still works
        done();
      },
    });
  });

  it('should degrade responseDelay to null on failure — never a zeroed object', (done) => {
    apiSpy.getFirstResponseTime.and.returnValue(of(mockFrt));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getConteosDeTickets.and.returnValue(of(mockConteos));
    apiSpy.getTendenciaDeVolumen.and.returnValue(of(mockVolumen));
    apiSpy.getMetricasDeAgente.and.returnValue(of(mockAgentes));
    mockGroupsBAndC();
    apiSpy.getDemoraDeRespuesta.and.returnValue(throwError(() => new Error('Response delay error')));

    useCase.execute(input).subscribe({
      next: (data: ReportsDashboardData) => {
        expect(data.responseDelay).toBeNull();
        done();
      },
    });
  });

  it('should pass through the responseDelay report when the call succeeds', (done) => {
    const mockDemoraDeRespuesta = {
      datosDisponiblesDesde: '2026-01-01T00:00:00Z',
      datosParciales: false,
      global: { demoraPromedioSegundos: 120, cantidadDeMuestras: 5, cantidadDeEntrantesSinResponder: 1 },
      porAgente: [],
    };

    apiSpy.getFirstResponseTime.and.returnValue(of(mockFrt));
    apiSpy.getResolutionTime.and.returnValue(of(mockRt));
    apiSpy.getConteosDeTickets.and.returnValue(of(mockConteos));
    apiSpy.getTendenciaDeVolumen.and.returnValue(of(mockVolumen));
    apiSpy.getMetricasDeAgente.and.returnValue(of(mockAgentes));
    mockGroupsBAndC();
    apiSpy.getDemoraDeRespuesta.and.returnValue(of(mockDemoraDeRespuesta));

    useCase.execute(input).subscribe({
      next: (data: ReportsDashboardData) => {
        expect(data.responseDelay).toEqual(mockDemoraDeRespuesta);
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
