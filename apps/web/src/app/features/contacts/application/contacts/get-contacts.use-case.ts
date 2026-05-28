import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ContactsApiService } from '../../../../core/api/contacts-api.service';
import {
  UIContact,
  ContactFilters,
  ContactPagination,
  mapListItemToUIContact,
} from '../../models';

/**
 * GetContactsInput — input DTO for GetContactsUseCase.
 */
export interface GetContactsInput {
  page?: number;
  limit?: number;
  filters?: ContactFilters;
}

/**
 * GetContactsOutput — output DTO for GetContactsUseCase.
 */
export interface GetContactsOutput {
  contacts: UIContact[];
  total: number;
  page: number;
  hasMore: boolean;
}

/**
 * GetContactsFailed — error thrown when contacts fetch fails.
 */
export class GetContactsFailed extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'GetContactsFailed';
  }
}

/**
 * GetContactsUseCase — Application Layer use case for fetching contacts.
 *
 * Per architecture spec:
 * - Accepts page, limit, filters
 * - Calls ContactsApiService
 * - Maps DTO→UIContact via mappers
 * - Returns paginated contacts with metadata
 * - Throws GetContactsFailed on error
 */
@Injectable({ providedIn: 'root' })
export class GetContactsUseCase {
  constructor(
    private readonly api: ContactsApiService,
  ) {}

  execute(input: GetContactsInput = {}): Observable<GetContactsOutput> {
    const page = input.page ?? 1;
    const limit = input.limit ?? 20;

    return this.api.getContacts(page, limit).pipe(
      map(response => {
        const contacts = response.data.map(mapListItemToUIContact);

        return {
          contacts,
          total: response.meta.total,
          page: response.meta.page,
          hasMore: response.meta.page * response.meta.limit < response.meta.total,
        };
      }),
      catchError(err => {
        console.error('[GetContactsUseCase] Failed to fetch contacts:', err);
        return throwError(() => new GetContactsFailed(
          err instanceof Error ? err.message : 'Failed to fetch contacts'
        ));
      })
    );
  }
}