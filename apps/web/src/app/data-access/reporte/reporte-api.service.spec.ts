import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ReporteApiService } from './reporte-api.service';
import { ParticipacionDeTicket } from './demora-de-respuesta.dto';
import { environment } from '../../../environments/environment';

describe('ReporteApiService', () => {
  let service: ReporteApiService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(ReporteApiService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  describe('getTicketParticipation', () => {
    const mockParticipacion: ParticipacionDeTicket = {
      ticket_id: 42,
      data_available_from: '2026-01-01T00:00:00Z',
      partial_data: false,
      participants: [
        { agent_id: 1, agent_name: 'Ana', message_count: 3, sample_count: 2, avg_delay_seconds: 90 },
      ],
      automatic_message_count: 1,
      unanswered_inbound_count: 0,
      pairs: [
        { run_start_at: '2026-01-01T10:00:00Z', reply_at: '2026-01-01T10:01:30Z', agent_id: 1, delay_seconds: 90 },
      ],
    };

    const urlFor = (id: number) => `${environment.apiUrl}/api/reports/tickets/${id}/participation`;

    it('GETs the participation of the given ticket and returns it', (done) => {
      service.getParticipacionDeTicket(42).subscribe({
        next: (result) => {
          expect(result).toEqual(mockParticipacion);
          done();
        },
      });

      const req = httpMock.expectOne(urlFor(42));
      expect(req.request.method).toBe('GET');
      req.flush(mockParticipacion);
    });

    it('maps API errors via extractApiError into an Error with the backend message', (done) => {
      service.getParticipacionDeTicket(999).subscribe({
        error: (err) => {
          expect(err).toBeInstanceOf(Error);
          expect(err.message).toBe('Ticket not found');
          done();
        },
      });

      httpMock
        .expectOne(urlFor(999))
        .flush({ error: 'Ticket not found' }, { status: 404, statusText: 'Not Found' });
    });

    it('falls back to a generic message when the error has no envelope', (done) => {
      service.getParticipacionDeTicket(1).subscribe({
        error: (err) => {
          expect(err).toBeInstanceOf(Error);
          expect(err.message).toBe('No se pudo cargar la participación del ticket');
          done();
        },
      });

      httpMock.expectOne(urlFor(1)).error(new ProgressEvent('error'));
    });
  });
});
