import { of, throwError } from 'rxjs';
import { EnviarMensajeUseCase, EnviarMensajeOutput, EnvioDeMensajeFallido } from './enviar-mensaje.use-case';
import { ProveedorWhatsAppCloud, Mensaje } from './proveedor-whatsapp-cloud';

describe('EnviarMensajeUseCase', () => {
  let useCase: EnviarMensajeUseCase;
  let proveedorSpy: jasmine.SpyObj<ProveedorWhatsAppCloud>;

  const mensajeDeProveedor: Mensaje = {
    id: 'provider-id-123',
    ticketId: 'ticket-1',
    content: 'Hello',
    sender: 'agent',
    deliveryStatus: 'enviado',
    createdAt: '2024-01-01T00:00:00Z',
  };

  beforeEach(() => {
    proveedorSpy = jasmine.createSpyObj('ProveedorWhatsAppCloud', ['enviarMensaje']);
    useCase = new EnviarMensajeUseCase(proveedorSpy);
  });

  it('should call provider.sendMessage() with ticketId and content', (done) => {
    proveedorSpy.enviarMensaje.and.returnValue(of(mensajeDeProveedor));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      next: () => {
        expect(proveedorSpy.enviarMensaje).toHaveBeenCalledWith('ticket-1', 'Hello');
        done();
      },
    });
  });

  it('should map provider response to EnviarMensajeOutput with tempId', (done) => {
    proveedorSpy.enviarMensaje.and.returnValue(of(mensajeDeProveedor));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: EnviarMensajeOutput) => {
        expect(output.tempId).toBe('my-temp-id');
        expect(output.message).toEqual(jasmine.objectContaining(mensajeDeProveedor));
        expect(output.message.tempId).toBe('my-temp-id');
        done();
      },
    });
  });

  it('should generate a tempId when caller does not provide one', (done) => {
    proveedorSpy.enviarMensaje.and.returnValue(of(mensajeDeProveedor));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      next: (output: EnviarMensajeOutput) => {
        expect(output.tempId).toBeTruthy();
        expect(output.tempId!.length).toBeGreaterThan(0);
        done();
      },
    });
  });

  it('should produce output with correct shape', (done) => {
    proveedorSpy.enviarMensaje.and.returnValue(of(mensajeDeProveedor));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: EnviarMensajeOutput) => {
        expect(output.tempId).toBe('my-temp-id');
        expect(output.message.id).toBe('provider-id-123');
        expect(output.message.ticketId).toBe('ticket-1');
        expect(output.message.content).toBe('Hello');
        expect(output.message.deliveryStatus).toBe('enviado');
        done();
      },
    });
  });

  it('should fall back to tempId as id when provider returns empty id', (done) => {
    const mensajeSinId: Mensaje = { ...mensajeDeProveedor, id: '' };
    proveedorSpy.enviarMensaje.and.returnValue(of(mensajeSinId));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: EnviarMensajeOutput) => {
        expect(output.message.id).toBe('my-temp-id');
        done();
      },
    });
  });

  it('should throw EnvioDeMensajeFallido when provider returns deliveryStatus=failed', (done) => {
    const mensajeFallido: Mensaje = { ...mensajeDeProveedor, deliveryStatus: 'fallido' };
    proveedorSpy.enviarMensaje.and.returnValue(of(mensajeFallido));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      error: (err: EnvioDeMensajeFallido) => {
        expect(err).toBeInstanceOf(EnvioDeMensajeFallido);
        done();
      },
    });
  });

  it('should throw EnvioDeMensajeFallido when provider observable errors', (done) => {
    proveedorSpy.enviarMensaje.and.returnValue(throwError(() => new Error('Network down')));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      error: (err: EnvioDeMensajeFallido) => {
        expect(err).toBeInstanceOf(EnvioDeMensajeFallido);
        done();
      },
    });
  });
});
