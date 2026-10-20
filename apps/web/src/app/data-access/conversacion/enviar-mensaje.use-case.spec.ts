import { of, throwError } from 'rxjs';
import { EnviarMensajeUseCase, EnviarMensajeOutput, EnvioDeMensajeFallido } from './enviar-mensaje.use-case';
import { ProveedorWhatsAppCloud, Mensaje } from './proveedor-whatsapp-cloud';

describe('EnviarMensajeUseCase', () => {
  let useCase: EnviarMensajeUseCase;
  let providerSpy: jasmine.SpyObj<ProveedorWhatsAppCloud>;

  const providerMessage: Mensaje = {
    id: 'provider-id-123',
    ticketId: 'ticket-1',
    content: 'Hello',
    sender: 'agent',
    deliveryStatus: 'sent',
    createdAt: '2024-01-01T00:00:00Z',
  };

  beforeEach(() => {
    providerSpy = jasmine.createSpyObj('ProveedorWhatsAppCloud', ['sendMessage']);
    useCase = new EnviarMensajeUseCase(providerSpy);
  });

  it('should call provider.sendMessage() with ticketId and content', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      next: () => {
        expect(providerSpy.sendMessage).toHaveBeenCalledWith('ticket-1', 'Hello');
        done();
      },
    });
  });

  it('should map provider response to EnviarMensajeOutput with tempId', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: EnviarMensajeOutput) => {
        expect(output.tempId).toBe('my-temp-id');
        expect(output.message).toEqual(jasmine.objectContaining(providerMessage));
        expect(output.message.tempId).toBe('my-temp-id');
        done();
      },
    });
  });

  it('should generate a tempId when caller does not provide one', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      next: (output: EnviarMensajeOutput) => {
        expect(output.tempId).toBeTruthy();
        expect(output.tempId!.length).toBeGreaterThan(0);
        done();
      },
    });
  });

  it('should produce output with correct shape', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: EnviarMensajeOutput) => {
        expect(output.tempId).toBe('my-temp-id');
        expect(output.message.id).toBe('provider-id-123');
        expect(output.message.ticketId).toBe('ticket-1');
        expect(output.message.content).toBe('Hello');
        expect(output.message.deliveryStatus).toBe('sent');
        done();
      },
    });
  });

  it('should fall back to tempId as id when provider returns empty id', (done) => {
    const noIdMessage: Mensaje = { ...providerMessage, id: '' };
    providerSpy.sendMessage.and.returnValue(of(noIdMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: EnviarMensajeOutput) => {
        expect(output.message.id).toBe('my-temp-id');
        done();
      },
    });
  });

  it('should throw EnvioDeMensajeFallido when provider returns deliveryStatus=failed', (done) => {
    const failedMessage: Mensaje = { ...providerMessage, deliveryStatus: 'failed' };
    providerSpy.sendMessage.and.returnValue(of(failedMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      error: (err: EnvioDeMensajeFallido) => {
        expect(err).toBeInstanceOf(EnvioDeMensajeFallido);
        done();
      },
    });
  });

  it('should throw EnvioDeMensajeFallido when provider observable errors', (done) => {
    providerSpy.sendMessage.and.returnValue(throwError(() => new Error('Network down')));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      error: (err: EnvioDeMensajeFallido) => {
        expect(err).toBeInstanceOf(EnvioDeMensajeFallido);
        done();
      },
    });
  });
});
