import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import {
  ContactoDeListaDTO,
  DetalleDeContactoDTO,
  ListaDeContactosResponse,
  CreateContactoRequest,
  UpdateContactoRequest
} from './contacto-transport.dto';
import { EventoDeLineaDeTiempoResponse } from './linea-de-tiempo-transport.dto';
import { ContactoUI } from './contacto-ui.model';
import { mapearDetalleAContactoUI, mapearItemDeListaAContactoUI } from './mappers';

/** Input of ContactoApiService.getContacts */
export interface GetContactosInput {
  page?: number;
  limit?: number;
}

/** Paginated contacts mapped to ContactoUI */
export interface GetContactosOutput {
  contacts: ContactoUI[];
  total: number;
  page: number;
  hasMore: boolean;
}

/** Input of ContactoApiService.createContact */
export interface CreateContactoInput {
  phone: string;
  name?: string;
  email?: string;
}

/** Input of ContactoApiService.updateContact */
export interface UpdateContactoInput {
  id: string;
  name?: string;
  email?: string;
  tags?: string[];
}

/** Per-row import failure, as returned by POST /api/contacts/import */
export interface ErrorDeImportacionDeContactos {
  row: number;
  phone: string;
  reason: string;
  message: string;
}

/** Partial-success result of a CSV contact import */
export interface ResultadoDeImportacionDeContactos {
  totalRows: number;
  imported: number;
  failed: number;
  errors: ErrorDeImportacionDeContactos[];
  errorsTruncated: boolean;
}

/**
 * ContactoApiService — HTTP client for contacts endpoints.
 *
 * Calls Symfony backend at:
 * - GET /api/contacts (paginated list with filters)
 * - GET /api/contacts/{id} (contact detail)
 * - POST /api/contacts (create contact)
 * - PUT /api/contacts/{id} (update contact)
 * - DELETE /api/contacts/{id} (soft delete)
 */
@Injectable({ providedIn: 'root' })
export class ContactoApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /**
   * Build query params for list endpoint
   */
  private buildListParams(page: number, limit: number, search?: string, filtros?: {
    status?: string;
    channel?: string;
    ownerId?: number;
  }): HttpParams {
    let params = new HttpParams()
      .set('page', page.toString())
      .set('limit', limit.toString());

    if (search) {
      params = params.set('search', search);
    }
    if (filtros?.status) {
      params = params.set('status', filtros.status);
    }
    if (filtros?.channel) {
      params = params.set('channel', filtros.channel);
    }
    if (filtros?.ownerId !== undefined && filtros?.ownerId !== null) {
      params = params.set('ownerId', filtros.ownerId.toString());
    }

    return params;
  }

  /**
   * Map backend response to frontend DTO
   */
  private mapearAContactoDeLista(item: any): ContactoDeListaDTO {
    return {
      id: String(item.id),
      phone: item.phone || '',
      name: item.name || null,
      lastInteraction: item.timestamps?.updated_at || null,
      unreadCount: 0, // Not available in backend yet
      activeTicketId: item.activeTicketId ? String(item.activeTicketId) : null,
      tags: item.tags || [], // Not available in backend yet
      ownerId: item.owner_id,
      email: item.email || null,
      ownerName: item.owner?.name || null,
      status: item.status === 'active' ? 'active' : 'inactive',
      channel: item.channel === 'whatsapp' ? 'whatsapp' : 'other',
      updatedAt: item.timestamps?.updated_at || new Date().toISOString(),
    };
  }

  /**
   * GET /api/contacts - Paginated list, mapped to ContactoUI.
   * Errors keep the original message when it is an Error; otherwise a generic one.
   */
  getContactos(input: GetContactosInput = {}): Observable<GetContactosOutput> {
    const page = input.page ?? 1;
    const limit = input.limit ?? 20;

    return this.http.get<any>(`${this.baseUrl}/api/contacts`, {
      params: this.buildListParams(page, limit)
    }).pipe(
      // Map response to frontend format
      map((response: any) => ({
        data: (response.data || []).map((item: any) => this.mapearAContactoDeLista(item)),
        meta: {
          total: response.meta?.total || 0,
          page: response.meta?.page || 1,
          limit: response.meta?.limit || limit,
        }
      })),
      map((response: ListaDeContactosResponse) => ({
        contacts: response.data.map(mapearItemDeListaAContactoUI),
        total: response.meta.total,
        page: response.meta.page,
        hasMore: response.meta.page * response.meta.limit < response.meta.total,
      })),
      catchError(err => {
        console.error('[ContactoApiService] Failed to fetch contacts:', err);
        return throwError(() => new Error(
          err instanceof Error ? err.message : 'Failed to fetch contacts'
        ));
      })
    );
  }

  /**
   * GET /api/contacts/{id} - Contact detail
   */
  getContacto(id: string): Observable<DetalleDeContactoDTO> {
    return this.http.get<any>(`${this.baseUrl}/api/contacts/${id}`).pipe(
      map((response: any) => ({
        ...this.mapearAContactoDeLista(response),
        createdAt: response.timestamps?.created_at || new Date().toISOString(),
        recentTickets: [], // Not available in backend yet
        email: response.email || null,
      }))
    );
  }

  /**
   * POST /api/contacts - Create contact (phone required; fields trimmed), mapped to ContactoUI.
   */
  createContacto(input: CreateContactoInput): Observable<ContactoUI> {
    if (!input.phone || input.phone.trim().length === 0) {
      return throwError(() => new Error('Phone is required'));
    }

    const request: CreateContactoRequest = {
      phone: input.phone.trim(),
      name: input.name?.trim(),
      email: input.email?.trim(),
    };

    return this.http.post<any>(`${this.baseUrl}/api/contacts`, request).pipe(
      map((response: any): DetalleDeContactoDTO => ({
        ...this.mapearAContactoDeLista(response),
        createdAt: response.timestamps?.created_at || new Date().toISOString(),
        recentTickets: [],
        email: response.email || null,
      })),
      map(dto => mapearDetalleAContactoUI(dto)),
      catchError(err => {
        console.error('[ContactoApiService] Failed to create contact:', err);
        return throwError(() => new Error(
          err instanceof Error ? err.message : 'Failed to create contact'
        ));
      })
    );
  }

  /**
   * POST /api/contacts/import - Bulk CSV contact import (ROLE_SUPERVISOR only).
   * Do not set Content-Type manually — the browser must add the multipart boundary.
   */
  importarContactos(file: File): Observable<ResultadoDeImportacionDeContactos> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<ResultadoDeImportacionDeContactos>(`${this.baseUrl}/api/contacts/import`, form);
  }

  /**
   * PUT /api/contacts/{id} - Update contact (id required; fields trimmed), mapped to ContactoUI.
   */
  updateContacto(input: UpdateContactoInput): Observable<ContactoUI> {
    if (!input.id) {
      return throwError(() => new Error('Contact ID is required'));
    }

    const request: UpdateContactoRequest = {
      name: input.name?.trim(),
      email: input.email?.trim(),
      tags: input.tags,
    };

    return this.http.put<any>(`${this.baseUrl}/api/contacts/${input.id}`, request).pipe(
      map((response: any): DetalleDeContactoDTO => ({
        ...this.mapearAContactoDeLista(response),
        createdAt: response.timestamps?.created_at || new Date().toISOString(),
        recentTickets: [],
        email: response.email || null,
      })),
      map(dto => mapearDetalleAContactoUI(dto)),
      catchError(err => {
        console.error('[ContactoApiService] Failed to update contact:', err);
        return throwError(() => new Error(
          err instanceof Error ? err.message : 'Failed to update contact'
        ));
      })
    );
  }

  /**
   * GET /api/contacts/{id}/timeline - Contact timeline events
   */
  getLineaDeTiempo(contactoId: number, before?: string, limit = 20): Observable<EventoDeLineaDeTiempoResponse> {
    let params = new HttpParams().set('limit', limit.toString());
    if (before) {
      params = params.set('before', before);
    }
    return this.http.get<EventoDeLineaDeTiempoResponse>(
      `${this.baseUrl}/api/contacts/${contactoId}/timeline`,
      { params }
    );
  }
}