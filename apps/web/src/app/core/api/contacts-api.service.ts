import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import {
  ContactListItemDTO,
  ContactDetailDTO,
  ContactsListResponse,
  CreateContactRequest,
  UpdateContactRequest
} from '../../features/contacts/models';

/**
 * ContactsApiService — HTTP client for contacts endpoints.
 *
 * Calls Symfony backend at:
 * - GET /api/contacts (paginated list with filters)
 * - GET /api/contacts/{id} (contact detail)
 * - POST /api/contacts (create contact)
 * - PUT /api/contacts/{id} (update contact)
 * - DELETE /api/contacts/{id} (soft delete)
 */
@Injectable({ providedIn: 'root' })
export class ContactsApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /**
   * Build query params for list endpoint
   */
  private buildListParams(page: number, limit: number, search?: string, filters?: {
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
    if (filters?.status) {
      params = params.set('status', filters.status);
    }
    if (filters?.channel) {
      params = params.set('channel', filters.channel);
    }
    if (filters?.ownerId !== undefined && filters?.ownerId !== null) {
      params = params.set('ownerId', filters.ownerId.toString());
    }

    return params;
  }

  /**
   * Map backend response to frontend DTO
   */
  private mapToContactListItem(item: any): ContactListItemDTO {
    return {
      id: String(item.id),
      phone: item.phone || '',
      name: item.name || null,
      lastInteraction: item.timestamps?.updated_at || null,
      unreadCount: 0, // Not available in backend yet
      activeTicketId: null, // Not available in backend yet
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
   * GET /api/contacts - Paginated list with optional filters
   */
  getContacts(page = 1, limit = 20, search?: string): Observable<ContactsListResponse> {
    return this.http.get<any>(`${this.baseUrl}/api/contacts`, {
      params: this.buildListParams(page, limit, search)
    }).pipe(
      // Map response to frontend format
      map((response: any) => ({
        data: (response.data || []).map((item: any) => this.mapToContactListItem(item)),
        meta: {
          total: response.meta?.total || 0,
          page: response.meta?.page || 1,
          limit: response.meta?.limit || limit,
        }
      }))
    );
  }

  /**
   * GET /api/contacts/{id} - Contact detail
   */
  getContact(id: string): Observable<ContactDetailDTO> {
    return this.http.get<any>(`${this.baseUrl}/api/contacts/${id}`).pipe(
      map((response: any) => ({
        ...this.mapToContactListItem(response),
        createdAt: response.timestamps?.created_at || new Date().toISOString(),
        recentTickets: [], // Not available in backend yet
        email: response.email || null,
      }))
    );
  }

  /**
   * POST /api/contacts - Create contact
   */
  createContact(request: CreateContactRequest): Observable<ContactDetailDTO> {
    return this.http.post<any>(`${this.baseUrl}/api/contacts`, request).pipe(
      map((response: any) => ({
        ...this.mapToContactListItem(response),
        createdAt: response.timestamps?.created_at || new Date().toISOString(),
        recentTickets: [],
        email: response.email || null,
      }))
    );
  }

  /**
   * PUT /api/contacts/{id} - Update contact
   */
  updateContact(id: string, request: UpdateContactRequest): Observable<ContactDetailDTO> {
    return this.http.put<any>(`${this.baseUrl}/api/contacts/${id}`, request).pipe(
      map((response: any) => ({
        ...this.mapToContactListItem(response),
        createdAt: response.timestamps?.created_at || new Date().toISOString(),
        recentTickets: [],
        email: response.email || null,
      }))
    );
  }

  /**
   * DELETE /api/contacts/{id} - Soft delete (sets status to inactive)
   */
  deleteContact(id: string): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/api/contacts/${id}`);
  }

  /**
   * Get empty contacts for testing empty state
   */
  getEmptyContacts(): Observable<ContactsListResponse> {
    return this.getContacts(1, 20);
  }
}