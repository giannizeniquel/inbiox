import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ContactsApiService } from '../../../../core/api/contacts-api.service';
import { UIContact, UpdateContactRequest, mapDetailToUIContact } from '../../models';

/**
 * UpdateContactInput — input DTO for UpdateContactUseCase.
 */
export interface UpdateContactInput {
  id: string;
  name?: string;
  email?: string;
  tags?: string[];
}

/**
 * UpdateContactOutput — output DTO for UpdateContactUseCase.
 */
export interface UpdateContactOutput {
  contact: UIContact;
}

/**
 * UpdateContactFailed — error thrown when contact update fails.
 */
export class UpdateContactFailed extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'UpdateContactFailed';
  }
}

/**
 * UpdateContactUseCase — Application Layer use case for updating contacts.
 *
 * Per architecture spec:
 * - Accepts id + update fields
 * - Calls ContactsApiService.updateContact
 * - Maps DTO→UIContact
 * - Returns updated contact
 * - Throws UpdateContactFailed on error
 */
@Injectable({ providedIn: 'root' })
export class UpdateContactUseCase {
  constructor(private readonly api: ContactsApiService) {}

  execute(input: UpdateContactInput): Observable<UpdateContactOutput> {
    if (!input.id) {
      return throwError(() => new UpdateContactFailed('Contact ID is required'));
    }

    const request: UpdateContactRequest = {
      name: input.name?.trim(),
      email: input.email?.trim(),
      tags: input.tags,
    };

    return this.api.updateContact(input.id, request).pipe(
      map(dto => ({
        contact: mapDetailToUIContact(dto),
      })),
      catchError(err => {
        console.error('[UpdateContactUseCase] Failed to update contact:', err);
        return throwError(() => new UpdateContactFailed(
          err instanceof Error ? err.message : 'Failed to update contact'
        ));
      })
    );
  }
}