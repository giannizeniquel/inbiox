import { of, throwError } from 'rxjs';
import { GetTicketParticipationUseCase, TicketParticipationFetchFailed } from './get-ticket-participation.use-case';
import { ReportsApiService } from '../../api/reports-api.service';
import { TicketParticipation } from '../../dto/response-delay.dto';

describe('GetTicketParticipationUseCase', () => {
  let useCase: GetTicketParticipationUseCase;
  let apiSpy: jasmine.SpyObj<ReportsApiService>;

  const mockParticipation: TicketParticipation = {
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

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('ReportsApiService', ['getTicketParticipation']);
    useCase = new GetTicketParticipationUseCase();
    (useCase as unknown as { reportsApi: ReportsApiService }).reportsApi = apiSpy;
  });

  it('should call ReportsApiService.getTicketParticipation with the ticket id', (done) => {
    apiSpy.getTicketParticipation.and.returnValue(of(mockParticipation));

    useCase.execute(42).subscribe({
      next: (result) => {
        expect(apiSpy.getTicketParticipation).toHaveBeenCalledWith(42);
        expect(result).toEqual(mockParticipation);
        done();
      },
    });
  });

  it('maps API errors via extractApiError into a TicketParticipationFetchFailed', (done) => {
    apiSpy.getTicketParticipation.and.returnValue(
      throwError(() => ({ error: { error: 'Ticket not found' } })),
    );

    useCase.execute(999).subscribe({
      error: (err) => {
        expect(err).toBeInstanceOf(TicketParticipationFetchFailed);
        expect(err.message).toBe('Ticket not found');
        done();
      },
    });
  });

  it('falls back to a generic message when the error has no envelope', (done) => {
    apiSpy.getTicketParticipation.and.returnValue(throwError(() => new Error('network down')));

    useCase.execute(1).subscribe({
      error: (err) => {
        expect(err).toBeInstanceOf(TicketParticipationFetchFailed);
        expect(err.message).toBe('network down');
        done();
      },
    });
  });
});
