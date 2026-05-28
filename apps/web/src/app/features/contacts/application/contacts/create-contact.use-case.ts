import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { ContactsApiService } from '../../../../core/api/contacts-api.service';
import {
  UIContact,
  ContactDetailDTO,
  CreateContactRequest,
  mapDetailToUIContact,
} from '../../models';

/**
 * CreateContactInput — input DTO for CreateContactUseCase.
 */
export interface CreateContactInput {
  phone: string;
  name?: string;
  email?: string;
}

/**
 * CreateContactOutput — output DTO for CreateContactUseCase.
 */
export interface CreateContactOutput {
  contact: UIContact;
}

/**
 * CreateContactFailed — error thrown when contact creation fails.
 */
export class CreateContactFailed extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'CreateContactFailed';
  }
}

/**
 * CreateContactUseCase — Application Layer use case for creating contacts.
 *
 * Per architecture spec:
 * - Accepts phone, name, email
 * - Calls ContactsApiService.createContact
 * - Maps DTO→UIContact
 * - Returns created contact
 * - Throws CreateContactFailed on error
 */
@Injectable({ providedIn: 'root' })
export class CreateContactUseCase {
  constructor(private readonly api: ContactsApiService) {}

  execute(input: CreateContactInput): Observable<CreateContactOutput> {
    if (!input.phone || input.phone.trim().length === 0) {
      return throwError(() => new CreateContactFailed('Phone is required'));
    }

    const request: CreateContactRequest = {
      phone: input.phone.trim(),
      name: input.name?.trim(),
      email: input.email?.trim(),
    };

    return this.api.createContact(request).pipe(
      map(dto => ({
        contact: mapDetailToUIContact(dto),
      })),
      catchError(err => {
        console.error('[CreateContactUseCase] Failed to create contact:', err);
        return throwError(() => new CreateContactFailed(
          err instanceof Error ? err.message : 'Failed to create contact'
        ));
      })
    );
  }
}