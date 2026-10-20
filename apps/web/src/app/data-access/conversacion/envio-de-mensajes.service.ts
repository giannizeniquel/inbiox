import { Injectable, inject } from '@angular/core';
import { Subject, switchMap, of, catchError, throwError } from 'rxjs';
import { EnviarMensajeUseCase } from './enviar-mensaje.use-case';
import { ReintentarMensajeUseCase } from './reintentar-mensaje.use-case';
import { ConversacionStore } from './conversacion.store';
import { Mensaje } from './mensaje.model';
import { generateTempId } from './temp-id.util';
import { retryWithBackoff, RetryConfig } from '../../core/http/retry.util';
import { Mensaje as ProviderMessage } from './proveedor-whatsapp-cloud';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../../environments/environment';

export interface ResultadoDeEnvio {
  tempId: string;
  success: boolean;
  message?: Mensaje;
  error?: string;
}

/**
 * EnvioDeMensajesService — handles optimistic send with RxJS retry.
 * Routes ALL sending through the Application Layer (EnviarMensajeUseCase / ReintentarMensajeUseCase).
 * Stores never call MensajeApiService directly.
 *
 * Per architecture spec:
 * - send(): Creates optimistic message + calls EnviarMensajeUseCase via retryWithBackoff
 * - retry(): Calls ReintentarMensajeUseCase with cached payload
 * - Optimistic UI: tempId → provider id reconciliation
 * - No nested setTimeout — retry pipeline is fully reactive
 */
@Injectable({ providedIn: 'root' })
export class EnvioDeMensajesService {
  private enviarMensajeUseCase = inject(EnviarMensajeUseCase);
  private reintentarMensajeUseCase = inject(ReintentarMensajeUseCase);
  private conversacionStore = inject(ConversacionStore);
  private http = inject(HttpClient);

  private readonly resultadosDeEnvio = new Subject<ResultadoDeEnvio>();
  readonly onResultadoDeEnvio$ = this.resultadosDeEnvio.asObservable();

  /** Retry config: max 2 retries (3 total attempts), backoff 1s → 2s, capped at 5s */
  private readonly retryConfig: RetryConfig = {
    maxRetries: 2,
    initialDelayMs: 1000,
    maxDelayMs: 5000,
  };

  /** Client-side file size caps (bytes) */
  private readonly FILE_SIZE_CAPS: Record<string, number> = {
    image: 5 * 1024 * 1024,
    video: 16 * 1024 * 1024,
    audio: 16 * 1024 * 1024,
    document: 100 * 1024 * 1024,
  };

  /**
   * Send a message with optimistic UI and RxJS retry pipeline.
   * Flow: tempId → optimistic → retryWithBackoff(EnviarMensajeUseCase) → confirm/fail
   *
   * @param ticketId - The ticket/chat ID
   * @param content - Message content
   * @param existingTempId - Optional pre-generated tempId (e.g., from ComposerComponent)
   * @returns tempId of the optimistic message
   */
  enviar(ticketId: string, content: string, existingTempId?: string): string {
    const tempId = existingTempId ?? generateTempId();

    // Add optimistic message to store (optimistic UI)
    this.conversacionStore.agregarMensajeOptimista(tempId, content, ticketId);

    // Route through EnviarMensajeUseCase → ProveedorWhatsAppCloud with RxJS retry
    retryWithBackoff(
      this.enviarMensajeUseCase.execute({ ticketId, content, tempId }),
      this.retryConfig
    ).subscribe({
      next: (output) => {
        // Success: reconcile optimistic → confirmed
        this.conversacionStore.confirmarMensaje(tempId, toCanonical(output.message, ticketId, tempId, content));
        this.resultadosDeEnvio.next({ tempId, success: true });
      },
      error: () => {
        // All retries exhausted — update to failed
        this.conversacionStore.updateEstadoDeMensaje(tempId, 'failed');
        this.resultadosDeEnvio.next({
          tempId,
          success: false,
          error: 'Send failed after retries',
        });
      },
    });

    return tempId;
  }

  /**
   * Send a message with a file attachment.
   * Flow: client-side validation → POST /api/provider/media/upload → send message with mediaId
   *
   * @param ticketId - The ticket/chat ID
   * @param content - Message content (caption)
   * @param file - File to attach
   * @param existingTempId - Optional pre-generated tempId
   * @returns tempId of the optimistic message
   */
  enviarConAdjunto(ticketId: string, content: string, file: File, existingTempId?: string): string {
    const tempId = existingTempId ?? generateTempId();

    // Build optimistic media metadata (with local blob URL for immediate preview)
    const adjuntoOptimista = this.armarAdjuntoOptimista(file, content);

    // Client-side pre-flight validation
    const validationError = this.validateFile(file);
    if (validationError) {
      this.conversacionStore.agregarMensajeOptimista(tempId, content, ticketId, adjuntoOptimista);
      this.conversacionStore.updateEstadoDeMensaje(tempId, 'failed');
      this.resultadosDeEnvio.next({
        tempId,
        success: false,
        error: validationError,
      });
      return tempId;
    }

    // Add optimistic message to store (with media for immediate preview)
    this.conversacionStore.agregarMensajeOptimista(tempId, content, ticketId, adjuntoOptimista);

    // Upload file to server
    const formData = new FormData();
    formData.append('file', file);
    formData.append('ticketId', ticketId);

    this.http.post<{ mediaId: string; key: string | null }>(
      `${environment.apiUrl}/api/provider/media/upload`,
      formData
    ).pipe(
      switchMap((resultadoDeSubida) => {
        // Now send the message with media
        return this.enviarMensajeUseCase.execute({
          ticketId,
          content,
          tempId,
          mediaId: resultadoDeSubida.mediaId,
          mediaKey: resultadoDeSubida.key ?? undefined,
          mimeType: file.type,
          caption: content,
          filename: file.name,
          size: file.size,
        });
      }),
      catchError((error) => {
        // Handle 413/415 as user-facing errors
        if (error.status === 413) {
          return throwError(() => new Error(`El archivo excede el tamaño máximo: ${error.error?.maxSize ? (error.error.maxSize / 1024 / 1024).toFixed(0) : '?'}MB`));
        }
        if (error.status === 415) {
          return throwError(() => new Error('Tipo de archivo no permitido'));
        }
        return throwError(() => error);
      })
    ).subscribe({
      next: (output) => {
        this.conversacionStore.confirmarMensaje(tempId, toCanonical(output.message, ticketId, tempId, content));
        this.resultadosDeEnvio.next({ tempId, success: true });
      },
      error: (err: unknown) => {
        console.error('[TipoDeRemitente] sendWithMedia failed:', err);
        this.conversacionStore.updateEstadoDeMensaje(tempId, 'failed');
        this.resultadosDeEnvio.next({
          tempId,
          success: false,
          error: err instanceof Error ? err.message : 'Send failed',
        });
      },
    });

    return tempId;
  }

  /**
   * Retry a failed message by tempId.
   * Reads cached payload from ConversacionStore and resubmits via ReintentarMensajeUseCase.
   *
   * @param tempId - The temporary ID of the failed message
   */
  reintentar(tempId: string): void {
    const cached = this.conversacionStore.getMensajeEnCache(tempId);
    if (!cached) {
      console.warn('[TipoDeRemitente] Cannot retry: no cached payload', tempId);
      return;
    }

    this.conversacionStore.updateEstadoDeMensaje(tempId, 'sending');

    this.reintentarMensajeUseCase.execute({
      tempId,
      originalContent: cached.content,
      ticketId: cached.ticketId,
    }).subscribe({
      next: (output) => {
        this.conversacionStore.confirmarMensaje(tempId, toCanonical(output.message, cached.ticketId, tempId, cached.content));
        this.resultadosDeEnvio.next({ tempId, success: true });
      },
      error: (err: unknown) => {
        console.error('[TipoDeRemitente] Retry failed:', err);
        this.conversacionStore.updateEstadoDeMensaje(tempId, 'failed');
        this.resultadosDeEnvio.next({
          tempId,
          success: false,
          error: 'Retry failed',
        });
      },
    });
  }

  /**
   * Remove a failed message.
   * @param tempId - The temporary ID of the message to remove
   */
  removeFailed(tempId: string): void {
    this.conversacionStore.quitarMensaje(tempId);
  }

  /**
   * Client-side file validation before upload.
   * Returns null if valid, error message string if invalid.
   */
  private validateFile(file: File): string | null {
    // Check MIME type
    const allowedTypes = [
      'image/jpeg', 'image/png', 'image/webp',
      'video/mp4',
      'audio/ogg', 'audio/mpeg',
      'application/pdf', 'text/plain',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'text/csv',
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    if (!allowedTypes.includes(file.type)) {
      return 'Tipo de archivo no permitido';
    }

    // Check size by kind
    const kind = this.getFileKind(file.type);
    const maxSize = this.FILE_SIZE_CAPS[kind];
    if (file.size > maxSize) {
      return `El archivo excede el tamaño máximo: ${maxSize / 1024 / 1024}MB`;
    }

    return null;
  }

  private getFileKind(mimeType: string): string {
    if (mimeType.startsWith('image/')) return 'image';
    if (mimeType.startsWith('video/')) return 'video';
    if (mimeType.startsWith('audio/')) return 'audio';
    return 'document';
  }

  /**
   * Build optimistic media metadata for immediate preview while sending.
   * Uses a local blob URL for image/video/audio so the file renders
   * before the server confirms (no pre-signed URL yet).
   */
  private armarAdjuntoOptimista(file: File, caption: string) {
    const kind = this.getFileKind(file.type) as 'image' | 'video' | 'audio' | 'document';
    const localUrl = kind !== 'document' ? URL.createObjectURL(file) : undefined;

    return {
      key: '',
      mimeType: file.type,
      filename: file.name,
      size: file.size,
      kind,
      caption: caption || undefined,
      localUrl,
    };
  }
}

/**
 * Map a provider-layer Message to the canonical Message used by the store.
 */
function toCanonical(
  pm: ProviderMessage,
  ticketId: string,
  tempId: string,
  content: string,
): Mensaje {
  return {
    id: pm.id || tempId,
    tempId,
    ticketId,
    content: pm.content || content,
    direction: 'outbound',
    sender: pm.sender ?? 'agent',
    deliveryStatus: pm.deliveryStatus,
    createdAt: pm.createdAt || new Date().toISOString(),
    externalId: pm.externalId,
    failedReason: pm.failedReason,
  };
}
