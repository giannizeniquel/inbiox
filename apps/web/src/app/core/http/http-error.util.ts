import { HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';

/**
 * Creates an HTTP error handler that logs with the given service name
 * and re-throws a descriptive error.
 *
 * Usage: `catchError(createHttpErrorHandler('TicketApi'))`
 */
export function createHttpErrorHandler(
  serviceName: string
): (error: HttpErrorResponse) => Observable<never> {
  return (error: HttpErrorResponse) => {
    console.error(`[${serviceName}] HTTP Error:`, error);
    return throwError(
      () => new Error(`${serviceName} error: ${error.status} - ${error.message}`)
    );
  };
}
