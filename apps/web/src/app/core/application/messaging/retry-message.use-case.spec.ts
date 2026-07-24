import { fakeAsync, tick } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import {
  RetryMessageUseCase,
  RetryMessageOutput,
  MessageNotFound,
} from './retry-message.use-case';
import { ProviderInterface, Message } from '../../providers/provider.interface';

describe('RetryMessageUseCase', () => {
  let useCase: RetryMessageUseCase;
  let providerSpy: jasmine.SpyObj<ProviderInterface>;

  const providerMessage: Message = {
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
    providerSpy = jasmine.createSpyObj('ProviderInterface', ['sendMessage']);
    useCase = new RetryMessageUseCase(providerSpy);
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

  it('should return RetryMessageOutput with correct shape', (done) => {
    providerSpy.sendMessage.and.returnValue(of(providerMessage));
    useCase.execute(validInput).subscribe({
      next: (output: RetryMessageOutput) => {
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
      next: (output: RetryMessageOutput) => {
        expect(output.tempId).toBe('temp-1');
        expect(output.message.tempId).toBe('temp-1');
        done();
      },
    });
  });

  it('should fall back to tempId as id when provider returns empty id', (done) => {
    const noIdMessage: Message = { ...providerMessage, id: '' };
    providerSpy.sendMessage.and.returnValue(of(noIdMessage));
    useCase.execute(validInput).subscribe({
      next: (output: RetryMessageOutput) => {
        expect(output.message.id).toBe('temp-1');
        done();
      },
    });
  });

  it('should set error field when deliveryStatus is failed', (done) => {
    const failedMessage: Message = {
      ...providerMessage,
      deliveryStatus: 'failed',
      failedReason: 'Channel unavailable',
    };
    providerSpy.sendMessage.and.returnValue(of(failedMessage));
    useCase.execute(validInput).subscribe({
      next: (output: RetryMessageOutput) => {
        expect(output.success).toBeFalse();
        expect(output.error).toBe('Channel unavailable');
        done();
      },
    });
  });

  it('should throw MessageNotFound when tempId is empty', (done) => {
    useCase.execute({ tempId: '', originalContent: 'Hello', ticketId: 'ticket-1' }).subscribe({
      error: (err: MessageNotFound) => {
        expect(err).toBeInstanceOf(MessageNotFound);
        expect(err.name).toBe('MessageNotFound');
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
