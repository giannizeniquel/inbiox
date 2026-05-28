import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ContactsApiService } from '../../../../core/api/contacts-api.service';
import { UIContact, mapListItemToUIContact } from '../../models';

/**
 * SearchContactsInput — input DTO for SearchContactsUseCase.
 */
export interface SearchContactsInput {
  query: string;
  page?: number;
  limit?: number;
}

/**
 * SearchContactsOutput — output DTO for SearchContactsUseCase.
 */
export interface SearchContactsOutput {
  contacts: UIContact[];
  total: number;
  page: number;
  hasMore: boolean;
}

/**
 * SearchContactsFailed — error thrown when contact search fails.
 */
export class SearchContactsFailed extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'SearchContactsFailed';
  }
}

/**
 * SearchContactsUseCase — Application Layer use case for searching contacts.
 *
 * Per architecture spec:
 * - Accepts search query + pagination
 * - Calls ContactsApiService with search term
 * - Maps DTO→UIContact via mappers
 * - Returns matching contacts with pagination
 * - Throws SearchContactsFailed on error
 */
@Injectable({ providedIn: 'root' })
export class SearchContactsUseCase {
  constructor(private readonly api: ContactsApiService) {}

  execute(input: SearchContactsInput): Observable<SearchContactsOutput> {
    const page = input.page ?? 1;
    const limit = input.limit ?? 20;

    if (!input.query || input.query.trim().length === 0) {
      return throwError(() => new SearchContactsFailed('Search query is required'));
    }

    return this.api.getContacts(page, limit, input.query.trim()).pipe(
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
        console.error('[SearchContactsUseCase] Failed to search contacts:', err);
        return throwError(() => new SearchContactsFailed(
          err instanceof Error ? err.message : 'Failed to search contacts'
        ));
      })
    );
  }
}