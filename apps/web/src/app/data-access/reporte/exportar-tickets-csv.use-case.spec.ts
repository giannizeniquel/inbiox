import { DOCUMENT } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { ExportarTicketsCsvUseCase } from './exportar-tickets-csv.use-case';
import { ReporteApiService } from './reporte-api.service';

/**
 * NOTE: Karma/Jasmine is not runnable in this environment (no Chrome/Chromium
 * binary under WSL2) — see `openspec/config.yaml`, testing.frontend. Written
 * for documentation/future-run value; `ng build` is the enforceable gate.
 */
describe('ExportarTicketsCsvUseCase', () => {
  let useCase: ExportarTicketsCsvUseCase;
  let apiSpy: jasmine.SpyObj<ReporteApiService>;
  let documentSpy: jasmine.SpyObj<Document>;
  let anchorSpy: jasmine.SpyObj<HTMLAnchorElement>;

  const params = { fromDate: '2026-01-01', toDate: '2026-01-31' };
  const mockBlob = new Blob(['Ticket,Contacto\n1,John'], { type: 'text/csv' });

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('ReporteApiService', ['exportarTicketsCsv']);

    anchorSpy = jasmine.createSpyObj('HTMLAnchorElement', ['click'], {
      href: '',
      download: '',
    });

    documentSpy = jasmine.createSpyObj('Document', ['createElement'], {
      body: jasmine.createSpyObj('HTMLElement', ['appendChild', 'removeChild']),
      defaultView: null,
    });
    documentSpy.createElement.and.returnValue(anchorSpy);

    TestBed.configureTestingModule({
      providers: [
        { provide: ReporteApiService, useValue: apiSpy },
        { provide: DOCUMENT, useValue: documentSpy },
      ],
    });
    useCase = TestBed.inject(ExportarTicketsCsvUseCase);
  });

  it('calls the export endpoint with the given params', (done) => {
    apiSpy.exportarTicketsCsv.and.returnValue(of(mockBlob));
    spyOn(URL, 'createObjectURL').and.returnValue('blob:mock-url');
    spyOn(URL, 'revokeObjectURL');

    useCase.execute(params).subscribe(() => {
      expect(apiSpy.exportarTicketsCsv).toHaveBeenCalledWith(params);
      done();
    });
  });

  it('triggers an anchor download with a filename derived from the date range', (done) => {
    apiSpy.exportarTicketsCsv.and.returnValue(of(mockBlob));
    spyOn(URL, 'createObjectURL').and.returnValue('blob:mock-url');
    spyOn(URL, 'revokeObjectURL');

    useCase.execute(params).subscribe(() => {
      expect(documentSpy.createElement).toHaveBeenCalledWith('a');
      expect(anchorSpy.download).toBe('tickets_2026-01-01_2026-01-31.csv');
      expect(anchorSpy.href).toBe('blob:mock-url');
      expect(anchorSpy.click).toHaveBeenCalled();
      expect(documentSpy.body.appendChild).toHaveBeenCalledWith(anchorSpy);
      expect(documentSpy.body.removeChild).toHaveBeenCalledWith(anchorSpy);
      expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock-url');
      done();
    });
  });

  it('falls back to "all" in the filename when no date params are given', (done) => {
    apiSpy.exportarTicketsCsv.and.returnValue(of(mockBlob));
    spyOn(URL, 'createObjectURL').and.returnValue('blob:mock-url');
    spyOn(URL, 'revokeObjectURL');

    useCase.execute().subscribe(() => {
      expect(anchorSpy.download).toBe('tickets_all_all.csv');
      done();
    });
  });
});
