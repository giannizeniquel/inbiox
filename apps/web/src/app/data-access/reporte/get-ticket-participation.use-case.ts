import { Injectable, inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { ReportsApiService } from './reports-api.service';
import { TicketParticipation } from './response-delay.dto';
import { extractApiError } from '../../core/http/extract-api-error';

/**
 * TicketParticipationFetchFailed — exception thrown when the per-ticket
 * participation fetch fails.
 */
export class TicketParticipationFetchFailed extends Error {
  constructor(message: string, public readonly originalError?: unknown) {
    super(message);
    this.name = 'TicketParticipationFetchFailed';
  }
}

/**
 * GetTicketParticipationUseCase — fetches the per-ticket participation
 * drill-down (participants, delay samples, automatic activity) for the
 * inbox "Participación" panel.
 *
 * Mirrors ExportTicketsCsvUseCase's thin-wrapper shape, but maps errors via
 * `extractApiError` (like GetReportsDashboardUseCase's ReportsFetchFailed)
 * since the panel needs a user-facing message, not a file download.
 */
@Injectable({ providedIn: 'root' })
export class GetTicketParticipationUseCase {
  private readonly reportsApi = inject(ReportsApiService);

  execute(ticketId: number): Observable<TicketParticipation> {
    return this.reportsApi.getTicketParticipation(ticketId).pipe(
      catchError((err) => {
        const message = extractApiError(err, 'No se pudo cargar la participación del ticket');
        return throwError(() => new TicketParticipationFetchFailed(message, err));
      }),
    );
  }
}
