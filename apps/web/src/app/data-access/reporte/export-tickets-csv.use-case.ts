import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { ReportsApiService } from './reports-api.service';
import { TimeFilterParams } from './reports.params';

/**
 * ExportTicketsCsvUseCase — downloads the filtered ticket-list CSV export
 * and triggers a client-side file save.
 *
 * `GET /api/reports/export` returns the CSV as a `Blob` (authenticated via
 * the existing HTTP interceptor, same as every other reports call — no
 * separate auth handling needed here). There is no existing "save blob as
 * file" helper in this codebase, so the anchor-click trigger is built here:
 * `URL.createObjectURL(blob)` → hidden `<a download>` → `click()` →
 * `URL.revokeObjectURL()` (`DOCUMENT` is injected so tests can spy on DOM
 * creation without touching the real document).
 */
@Injectable({ providedIn: 'root' })
export class ExportTicketsCsvUseCase {
  private readonly reportsApi = inject(ReportsApiService);
  private readonly document = inject(DOCUMENT);

  execute(params?: TimeFilterParams): Observable<Blob> {
    return this.reportsApi.exportTicketsCsv(params).pipe(
      tap((blob) => this.triggerDownload(blob, params)),
    );
  }

  private triggerDownload(blob: Blob, params?: TimeFilterParams): void {
    const url = this.document.defaultView
      ? this.document.defaultView.URL.createObjectURL(blob)
      : URL.createObjectURL(blob);

    const filename = this.buildFilename(params);

    const anchor = this.document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    this.document.body.appendChild(anchor);
    anchor.click();
    this.document.body.removeChild(anchor);

    (this.document.defaultView ? this.document.defaultView.URL : URL).revokeObjectURL(url);
  }

  private buildFilename(params?: TimeFilterParams): string {
    const from = params?.fromDate ?? 'all';
    const to = params?.toDate ?? 'all';
    return `tickets_${from}_${to}.csv`;
  }
}
