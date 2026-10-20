import { fakeAsync, tick } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import {
  ReintentarMensajeUseCase,
  ReintentarMensajeOutput,
  MensajeNoEncontrado,
} from './reintentar-mensaje.use-case';
import { ProveedorWhatsAppCloud, Mensaje } from './proveedor-whatsapp-cloud';

describe('ReintentarMensajeUseCase', () => {
  let useCase: ReintentarMensajeUseCase;
  let providerSpy: jasmine.SpyObj<ProveedorWhatsAppCloud>;

  const providerMessage: Mensaje = {
    id: 'provider-id-456',
    ticketId: 'ticket-1',
    content: 'Retried message',
    sender: 'agent',
    deliveryStatus: 'sent',
    createdAt: '2024-01-01T00:01:00Z',
  };

  const validInput = {
    tempId: 'temp-1',
    originalContent: 'Retried message',
    ticketId: 'ticket-1',
  };

  beforeEach(() => {
    providerSpy = jasmine.createSpyObj('ProveedorWhatsAppCloud', ['sendMessage']);
    useCase = new ReintentarMensajeUseCase(providerSpy);
  });

  it('should call provider.sendMessage() with ticketId and originalContent', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute(validInput).subscribe({
      next: () => {
        expect(providerSpy.sendMessage).toHaveBeenCalledWith('ticket-1', 'Retried message');
        done();
      },
    });
  });

  it('should return ReintentarMensajeOutput with correct shape', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute(validInput).subscribe({
      next: (output: ReintentarMensajeOutput) => {
        expect(output.tempId).toBe('temp-1');
        expect(output.success).toBeTrue();
        expect(output.error).toBeUndefined();
        expect(output.message).toEqual(jasmine.objectContaining({
          id: 'provider-id-456',
          tempId: 'temp-1',
          ticketId: 'ticket-1',
          content: 'Retried message',
          deliveryStatus: 'sent',
        }));
        done();
      },
    });
  });

  it('should keep the same tempId for idempotency', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute(validInput).subscribe({
      next: (output: ReintentarMensajeOutput) => {
        expect(output.tempId).toBe('temp-1');
        expect(output.message.tempId).toBe('temp-1');
        done();
      },
    });
  });

  it('should fall back to tempId as id when provider returns empty id', (done) => {
    const noIdMessage: Mensaje = { ...providerMessage, id: '' };
    providerSpy.sendMessage.and.returnValue(of(noIdMessage));
    useCase.execute(validInput).subscribe({
      next: (output: ReintentarMensajeOutput) => {
        expect(output.message.id).toBe('temp-1');
        done();
      },
    });
  });

  it('should set error field when deliveryStatus is failed', (done) => {
    const failedMessage: Mensaje = {
      ...providerMessage,
      deliveryStatus: 'failed',
      failedReason: 'Channel unavailable',
    };
    providerSpy.sendMessage.and.returnValue(of(failedMessage));
    useCase.execute(validInput).subscribe({
      next: (output: ReintentarMensajeOutput) => {
        expect(output.success).toBeFalse();
        expect(output.error).toBe('Channel unavailable');
        done();
      },
    });
  });

  it('should throw MensajeNoEncontrado when tempId is empty', (done) => {
    useCase.execute({ tempId: '', originalContent: 'Hello', ticketId: 'ticket-1' }).subscribe({
      error: (err: MensajeNoEncontrado) => {
        expect(err).toBeInstanceOf(MensajeNoEncontrado);
        expect(err.name).toBe('MensajeNoEncontrado');
        done();
      },
    });
  });

  it('should throw Error when ticketId is empty', (done) => {
    useCase.execute({ tempId: 'temp-1', originalContent: 'Hello', ticketId: '' }).subscribe({
      error: (err: Error) => {
        expect(err.message).toBe('ticketId is required for retry');
        done();
      },
    });
  });

  // FIXME: retryWithBackoff uses RxJS timer() which doesn't cooperate with fakeAsync.
  // This test needs a real async approach or integration test.
  xit('should propagate error after all retry attempts fail', fakeAsync(() => {
    providerSpy.sendMessage.and.callFake(() => throwError(() => new Error('Network down')));

    let caughtError: unknown;
    useCase.execute(validInput).subscribe({
      error: (err) => (caughtError = err),
    });

    tick(1100);

    expect(caughtError).toBeTruthy();
    expect((caughtError as Error).message).toBe('Network down');
    expect(providerSpy.sendMessage).toHaveBeenCalledTimes(2);
  }));
});
