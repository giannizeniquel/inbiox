import { DOCUMENT } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { ExportTicketsCsvUseCase } from './export-tickets-csv.use-case';
import { ReportsApiService } from '../../api/reports-api.service';

/**
 * NOTE: Karma/Jasmine is not runnable in this environment (no Chrome/Chromium
 * binary under WSL2) — see `openspec/config.yaml`, testing.frontend. Written
 * for documentation/future-run value; `ng build` is the enforceable gate.
 */
describe('ExportTicketsCsvUseCase', () => {
  let useCase: ExportTicketsCsvUseCase;
  let apiSpy: jasmine.SpyObj<ReportsApiService>;
  let documentSpy: jasmine.SpyObj<Document>;
  let anchorSpy: jasmine.SpyObj<HTMLAnchorElement>;

  const params = { fromDate: '2026-01-01', toDate: '2026-01-31' };
  const mockBlob = new Blob(['ticket_id,contact_name\n1,John'], { type: 'text/csv' });

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('ReportsApiService', ['exportTicketsCsv']);

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
        { provide: ReportsApiService, useValue: apiSpy },
        { provide: DOCUMENT, useValue: documentSpy },
      ],
    });
    useCase = TestBed.inject(ExportTicketsCsvUseCase);
  });

  it('calls the export endpoint with the given params', (done) => {
    apiSpy.exportTicketsCsv.and.returnValue(of(mockBlob));
    spyOn(URL, 'createObjectURL').and.returnValue('blob:mock-url');
    spyOn(URL, 'revokeObjectURL');

    useCase.execute(params).subscribe(() => {
      expect(apiSpy.exportTicketsCsv).toHaveBeenCalledWith(params);
      done();
    });
  });

  it('triggers an anchor download with a filename derived from the date range', (done) => {
    apiSpy.exportTicketsCsv.and.returnValue(of(mockBlob));
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
    apiSpy.exportTicketsCsv.and.returnValue(of(mockBlob));
    spyOn(URL, 'createObjectURL').and.returnValue('blob:mock-url');
    spyOn(URL, 'revokeObjectURL');

    useCase.execute().subscribe(() => {
      expect(anchorSpy.download).toBe('tickets_all_all.csv');
      done();
    });
  });
});
