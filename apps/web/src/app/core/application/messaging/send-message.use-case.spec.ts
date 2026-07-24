import { of, throwError } from 'rxjs';
import { SendMessageUseCase, SendMessageOutput, MessageSendFailed } from './send-message.use-case';
import { ProviderInterface, Message } from '../../providers/provider.interface';

describe('SendMessageUseCase', () => {
  let useCase: SendMessageUseCase;
  let providerSpy: jasmine.SpyObj<ProviderInterface>;

  const providerMessage: Message = {
    id: 'provider-id-123',
    ticketId: 'ticket-1',
    content: 'Hello',
    sender: 'agent',
    deliveryStatus: 'sent',
    createdAt: '2024-01-01T00:00:00Z',
  };

  beforeEach(() => {
    providerSpy = jasmine.createSpyObj('ProviderInterface', ['sendMessage']);
    useCase = new SendMessageUseCase(providerSpy);
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

  it('should map provider response to SendMessageOutput with tempId', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: SendMessageOutput) => {
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
      next: (output: SendMessageOutput) => {
        expect(output.tempId).toBeTruthy();
        expect(output.tempId!.length).toBeGreaterThan(0);
        done();
      },
    });
  });

  it('should produce output with correct shape', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: SendMessageOutput) => {
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
    const noIdMessage: Message = { ...providerMessage, id: '' };
    providerSpy.sendMessage.and.returnValue(of(noIdMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello', tempId: 'my-temp-id' }).subscribe({
      next: (output: SendMessageOutput) => {
        expect(output.message.id).toBe('my-temp-id');
        done();
      },
    });
  });

  it('should throw MessageSendFailed when provider returns deliveryStatus=failed', (done) => {
    const failedMessage: Message = { ...providerMessage, deliveryStatus: 'failed' };
    providerSpy.sendMessage.and.returnValue(of(failedMessage));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      error: (err: MessageSendFailed) => {
        expect(err).toBeInstanceOf(MessageSendFailed);
        done();
      },
    });
  });

  it('should throw MessageSendFailed when provider observable errors', (done) => {
    providerSpy.sendMessage.and.returnValue(throwError(() => new Error('Network down')));
    useCase.execute({ ticketId: 'ticket-1', content: 'Hello' }).subscribe({
      error: (err: MessageSendFailed) => {
        expect(err).toBeInstanceOf(MessageSendFailed);
        done();
      },
    });
  });
});
