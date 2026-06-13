/**
 * WhatsAppCloudProvider — Real WhatsApp Cloud API implementation of ProviderInterface.
 *
 * This is a DROP-IN replacement for FakeWhatsappProvider.
 * It delegates ALL Graph API calls to the backend proxy (/api/provider/*)
 * so that the META_ACCESS_TOKEN never reaches the browser.
 *
 * Pipeline (Sprint 12.5 compatible):
 *   ComposerComponent → MessageSenderService → SendMessageUseCase
 *     → WhatsAppCloudProvider.sendMessage() → HTTP POST /api/provider/send
 *       → ProviderProxyController → GraphClient → Graph API → WhatsApp
 *
 * The optimistic → confirm lifecycle is handled by MessageSenderService:
 *   - Optimistic:    MessageSenderService calls conversationStore.addOptimisticMessage()
 *   - Send:          WhatsAppCloudProvider.sendMessage() calls backend proxy
 *   - Confirm:       MessageSenderService calls conversationStore.confirmMessage()
 *   - SSE reconcile: Mercure events update deliveryStatus independently
 *
 * NOT to do:
 *   - ❌ Write directly to ConversationStore (bypasses optimistic→confirm flow)
 *   - ❌ Bypass MessageSenderService (use the existing send pipeline)
 *   - ❌ Introduce new state stores (use Signal stores only)
 *   - ❌ Reintroduce applyStorePatch or dual dispatch
 */
import { HttpClient } from '@angular/common/http';
import { Observable, of, throwError } from 'rxjs';
import { map } from 'rxjs/operators';
import {
  ProviderInterface,
  Message,
  Ticket,
  DeliveryStatus,
  MessageSender,
  FsmStatus,
  InboundCallback,
} from './provider.interface';

export class WhatsAppCloudProvider implements ProviderInterface {
  constructor(private readonly http: HttpClient) {}

  /**
   * Send a message via backend proxy.
   *
   * POST /api/provider/send { ticketId, content }
   * Response: { messageId: string, status: string }
   *
   * The returned Message carries the WAMID as `id`.
   * MessageSenderService handles optimistic→confirm reconciliation.
   */
  sendMessage(ticketId: string, content: string): Observable<Message> {
    return this.http.post<{ messageId: string; status: string }>(
      '/api/provider/send',
      { ticketId, content }
    ).pipe(
      map(response => ({
        id: response.messageId,        // WAMID from Graph API
        ticketId,
        content,
        deliveryStatus: 'sent' as DeliveryStatus,
        createdAt: new Date().toISOString(),
        sender: 'agent' as MessageSender,
      }))
    );
  }

  /**
   * Get messages for a ticket via backend proxy.
   * Stub — returns empty array. Full implementation in Sprint 13.4+.
   */
  getMessages(_ticketId: string): Observable<Message[]> {
    return of([]);
  }

  /**
   * Get a single ticket. Not supported via cloud provider.
   */
  getTicket(_id: string): Observable<Ticket> {
    return throwError(() => new Error('Not supported via cloud provider'));
  }

  /**
   * Get all tickets. Not supported via cloud provider — uses REST API instead.
   */
  getTickets(): Observable<Ticket[]> {
    return of([]);
  }

  /**
   * Update ticket FSM status. Not supported via cloud provider — uses REST API.
   */
  updateTicketStatus(_ticketId: string, _status: FsmStatus): Observable<Ticket> {
    return throwError(() => new Error('Not supported'));
  }

  /**
   * Register inbound message callback. Webhook-driven, no-op for now.
   * Inbound messages arrive via Mercure/SSE, not through this provider.
   */
  onInboundMessage(_callback: InboundCallback): void {
    // Webhook-driven — no-op
  }

  /**
   * Get delivery status of a specific message.
   * Stub — returns 'sent'. Full status tracking arrives in Sprint 13.2+.
   */
  getMessageStatus(_messageId: string): Observable<DeliveryStatus> {
    return of('sent' as DeliveryStatus);
  }

  /**
   * Disconnect from provider. No-op — cloud provider has no persistent connection.
   */
  disconnect(): void {
    // No-op
  }

  /**
   * Connect to provider. No-op — cloud provider uses HTTP requests, not persistent connection.
   */
  connect(): void {
    // No-op
  }

  // ──────────────────────────────────────────────
  //  Media Support (CAP-8 — Sprint 13.4)
  // ──────────────────────────────────────────────

  /**
   * Upload a media file to WhatsApp via backend proxy.
   *
   * POST /api/provider/media/upload (multipart/form-data)
   * Response: { mediaId: string }
   */
  uploadMedia(file: Blob, mimeType: string): Observable<{mediaId: string}> {
    const form = new FormData();
    // Infer file extension from MIME type for the filename
    const ext = mimeType.split('/')[1] || 'bin';
    form.append('file', file, `upload.${ext}`);
    return this.http.post<{mediaId: string}>('/api/provider/media/upload', form);
  }

  /**
   * Download a media file from WhatsApp via backend proxy.
   *
   * GET /api/provider/media/{mediaId}
   * Response: binary Blob
   */
  downloadMedia(mediaId: string): Observable<Blob> {
    return this.http.get(`/api/provider/media/${mediaId}`, { responseType: 'blob' });
  }

  /**
   * Send a message with a media attachment via backend proxy.
   *
   * POST /api/provider/send { ticketId, content, mediaId, mimeType }
   * Response: Message
   */
  sendMediaMessage(ticketId: string, mediaId: string, mimeType: string, caption?: string): Observable<Message> {
    return this.http.post<Message>('/api/provider/send', {
      ticketId,
      content: caption || '',
      mediaId,
      mimeType,
    });
  }
}
