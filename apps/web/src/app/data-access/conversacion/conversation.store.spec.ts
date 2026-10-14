import { ConversationStore } from './conversation.store';
import { Message } from './message.model';
import { RealtimeEventType, MessageAddedPayload } from '../../core/realtime/realtime-event.model';

describe('ConversationStore', () => {
  let store: ConversationStore;

  function makeMessage(overrides: Partial<Message> = {}): Message {
    return {
      id: 'msg-1',
      ticketId: 'ticket-1',
      content: 'Hello',
      direction: 'inbound',
      sender: 'customer',
      deliveryStatus: 'delivered',
      createdAt: '2024-01-01T00:00:00Z',
      ...overrides,
    };
  }

  beforeEach(() => {
    store = new ConversationStore();
  });

  // ── Initial state ──

  it('should start with empty messages, not loading, hasMore=true', () => {
    expect(store.messages()).toEqual([]);
    expect(store.loading()).toBeFalse();
    expect(store.hasMore()).toBeTrue();
  });

  // ── addMessage() ──

  it('should add a message', () => {
    const msg = makeMessage();
    store.addMessage(msg);
    expect(store.messages().length).toBe(1);
    expect(store.messages()[0]).toEqual(msg);
  });

  it('should enforce MAX_MESSAGES cap of 200', () => {
    const msgs = Array.from({ length: 210 }, (_, i) =>
      makeMessage({ id: `msg-${i}`, createdAt: `2024-01-01T00:${String(i).padStart(2, '0')}:00Z` })
    );
    msgs.forEach(m => store.addMessage(m));

    expect(store.messages().length).toBe(200);
    // Should keep the newest 200 (sliced from end)
    expect(store.messages()[0].id).toBe('msg-10');
    expect(store.messages()[199].id).toBe('msg-209');
  });

  // ── addOptimisticMessage() ──

  it('should create optimistic message with deliveryStatus=sending, tempId, and cache it', () => {
    const optimistic = store.addOptimisticMessage('temp-1', 'Hi there', 'ticket-1');

    expect(optimistic.id).toBe('temp-1');
    expect(optimistic.tempId).toBe('temp-1');
    expect(optimistic.ticketId).toBe('ticket-1');
    expect(optimistic.content).toBe('Hi there');
    expect(optimistic.direction).toBe('outbound');
    expect(optimistic.sender).toBe('agent');
    expect(optimistic.deliveryStatus).toBe('sending');
    expect(optimistic.createdAt).toBeTruthy();

    // Should be in the messages list
    expect(store.messages().length).toBe(1);
    expect(store.messages()[0].tempId).toBe('temp-1');

    // Should be cached for retry
    expect(store.getCachedMessage('temp-1')).toEqual({ content: 'Hi there', ticketId: 'ticket-1' });
  });

  // ── updateMessageStatus() ──

  it('should update deliveryStatus by id', () => {
    store.addMessage(makeMessage({ id: 'msg-1', deliveryStatus: 'sending' }));

    store.updateMessageStatus('msg-1', 'sent');

    expect(store.messages()[0].deliveryStatus).toBe('sent');
  });

  it('should update deliveryStatus by tempId', () => {
    store.addMessage(makeMessage({ id: 'msg-1', tempId: 'temp-1', deliveryStatus: 'sending' }));

    store.updateMessageStatus('temp-1', 'delivered');

    expect(store.messages()[0].deliveryStatus).toBe('delivered');
  });

  // ── confirmMessage() ──

  it('should replace optimistic with confirmed, keep tempId, clear cache', () => {
    store.addOptimisticMessage('temp-1', 'Hello', 'ticket-1');
    expect(store.getCachedMessage('temp-1')).toBeTruthy();

    const confirmed: Message = {
      id: 'server-id-123',
      ticketId: 'ticket-1',
      content: 'Hello',
      direction: 'outbound',
      sender: 'agent',
      deliveryStatus: 'sent',
      createdAt: '2024-01-01T00:01:00Z',
      externalId: 'ext-123',
    };

    store.confirmMessage('temp-1', confirmed);

    const msg = store.messages()[0];
    expect(msg.id).toBe('server-id-123');
    expect(msg.tempId).toBe('temp-1'); // tempId preserved
    expect(msg.deliveryStatus).toBe('sent');
    expect(msg.externalId).toBe('ext-123');

    // Cache should be cleared
    expect(store.getCachedMessage('temp-1')).toBeUndefined();
  });

  // ── removeMessage() ──

  it('should remove by id', () => {
    store.addMessage(makeMessage({ id: 'msg-1' }));
    store.addMessage(makeMessage({ id: 'msg-2' }));

    store.removeMessage('msg-1');

    expect(store.messages().length).toBe(1);
    expect(store.messages()[0].id).toBe('msg-2');
  });

  it('should remove by tempId and clear cache', () => {
    store.addOptimisticMessage('temp-1', 'Hello', 'ticket-1');
    expect(store.messages().length).toBe(1);
    expect(store.getCachedMessage('temp-1')).toBeTruthy();

    store.removeMessage('temp-1');

    expect(store.messages().length).toBe(0);
    expect(store.getCachedMessage('temp-1')).toBeUndefined();
  });

  // ── retryMessage() ──

  it('should reset status to sending and return cached payload', () => {
    store.addOptimisticMessage('temp-1', 'Hello', 'ticket-1');
    store.updateMessageStatus('temp-1', 'failed');
    expect(store.messages()[0].deliveryStatus).toBe('failed');

    const cached = store.retryMessage('temp-1');

    expect(cached).toEqual({ content: 'Hello', ticketId: 'ticket-1' });
    expect(store.messages()[0].deliveryStatus).toBe('sending');
  });

  it('should return null if no cached payload exists', () => {
    const cached = store.retryMessage('nonexistent');
    expect(cached).toBeNull();
  });

  // ── loadMessages() ──

  it('should replace all messages (default)', () => {
    store.addMessage(makeMessage({ id: 'old-1' }));

    const newMsgs = [
      makeMessage({ id: 'new-1' }),
      makeMessage({ id: 'new-2' }),
    ];
    store.loadMessages(newMsgs);

    expect(store.messages().length).toBe(2);
    expect(store.messages()[0].id).toBe('new-1');
  });

  it('should cap at 200 when replacing', () => {
    const msgs = Array.from({ length: 250 }, (_, i) =>
      makeMessage({ id: `msg-${i}` })
    );
    store.loadMessages(msgs);

    expect(store.messages().length).toBe(200);
    // Keeps the last 200 (newest)
    expect(store.messages()[0].id).toBe('msg-50');
  });

  it('should append when append=true', () => {
    store.loadMessages([makeMessage({ id: 'msg-1' })]);

    store.loadMessages([makeMessage({ id: 'msg-2' })], true);

    expect(store.messages().length).toBe(2);
    expect(store.messages()[0].id).toBe('msg-1');
    expect(store.messages()[1].id).toBe('msg-2');
  });

  // ── prependMessages() ──

  it('should add older messages at start', () => {
    store.loadMessages([makeMessage({ id: 'new-1' })]);

    store.prependMessages([makeMessage({ id: 'old-1' }), makeMessage({ id: 'old-2' })]);

    expect(store.messages().length).toBe(3);
    expect(store.messages()[0].id).toBe('old-1');
    expect(store.messages()[1].id).toBe('old-2');
    expect(store.messages()[2].id).toBe('new-1');
  });

  it('should cap at 200 trimming from newest (end)', () => {
    const current = Array.from({ length: 190 }, (_, i) =>
      makeMessage({ id: `current-${i}` })
    );
    store.loadMessages(current);

    const older = Array.from({ length: 30 }, (_, i) =>
      makeMessage({ id: `older-${i}` })
    );
    store.prependMessages(older);

    expect(store.messages().length).toBe(200);
    // Older messages at the start
    expect(store.messages()[0].id).toBe('older-0');
    // Newest trimmed from end
    expect(store.messages()[199].id).toBe('current-169');
  });

  // ── setLoading() / setHasMore() ──

  it('should toggle loading flag', () => {
    store.setLoading(true);
    expect(store.loading()).toBeTrue();

    store.setLoading(false);
    expect(store.loading()).toBeFalse();
  });

  it('should toggle hasMore flag', () => {
    expect(store.hasMore()).toBeTrue();

    store.setHasMore(false);
    expect(store.hasMore()).toBeFalse();

    store.setHasMore(true);
    expect(store.hasMore()).toBeTrue();
  });

  // ── findById() ──

  it('should find by id', () => {
    store.addMessage(makeMessage({ id: 'msg-1' }));
    expect(store.findById('msg-1')?.id).toBe('msg-1');
  });

  it('should find by tempId', () => {
    store.addMessage(makeMessage({ id: 'msg-1', tempId: 'temp-1' }));
    expect(store.findById('temp-1')?.id).toBe('msg-1');
  });

  it('should return undefined when not found', () => {
    expect(store.findById('nonexistent')).toBeUndefined();
  });

  // ── reset() ──

  it('should clear everything including cache', () => {
    store.addOptimisticMessage('temp-1', 'Hello', 'ticket-1');
    store.setLoading(true);
    store.setHasMore(false);

    store.reset();

    expect(store.messages()).toEqual([]);
    expect(store.loading()).toBeFalse();
    expect(store.hasMore()).toBeTrue();
    expect(store.getCachedMessage('temp-1')).toBeUndefined();
  });

  // ── hasMessage() ──

  it('should check existence by tempId', () => {
    store.addOptimisticMessage('temp-1', 'Hello', 'ticket-1');

    expect(store.hasMessage('temp-1')).toBeTrue();
    expect(store.hasMessage('nonexistent')).toBeFalse();
  });

  // ── onRealtimeEvent(MessageAdded) dedup guard (message-agent-attribution, design.md D6) ──
  // A human-agent reply now also broadcasts over Mercure, so the sender's own
  // optimistic confirmMessage() may already hold this server id before the
  // echo arrives — the echo must be a no-op, never a second bubble.

  function makeMessageAddedEvent(overrides: Partial<MessageAddedPayload> = {}) {
    return {
      eventId: 'evt-1',
      eventType: RealtimeEventType.MessageAdded,
      ticketId: 'ticket-1',
      payload: {
        messageId: 501,
        ticketId: 501,
        content: 'Echoed reply',
        direction: 'outbound' as const,
        senderName: 'Agent A',
        createdAt: '2024-01-01T00:05:00Z',
        externalId: null,
        ...overrides,
      },
    };
  }

  it('should ignore a MessageAdded echo whose id is already in messages()', () => {
    (store as unknown as { ticketStore: { activeTicket(): unknown } }).ticketStore = {
      activeTicket: () => ({ ticket_id: 'ticket-1' }),
    };
    store.addMessage(makeMessage({ id: '501' }));

    (store as unknown as { onRealtimeEvent(e: unknown): void }).onRealtimeEvent(
      makeMessageAddedEvent()
    );

    expect(store.messages().length).toBe(1);
  });

  it('should add a MessageAdded event for a message id not yet present', () => {
    (store as unknown as { ticketStore: { activeTicket(): unknown } }).ticketStore = {
      activeTicket: () => ({ ticket_id: 'ticket-1' }),
    };

    (store as unknown as { onRealtimeEvent(e: unknown): void }).onRealtimeEvent(
      makeMessageAddedEvent()
    );

    expect(store.messages().length).toBe(1);
    expect(store.messages()[0].id).toBe('501');
  });

  // ── confirmMessage() echo-arrives-first race guard (design.md D6) ──

  it('should drop a duplicate entry sharing the confirmed server id from an earlier echo', () => {
    // Echo arrived first and was appended directly (no tempId).
    store.addMessage(makeMessage({ id: 'server-id-123', tempId: undefined }));
    // Optimistic entry for the same logical send, still pending confirmation.
    store.addOptimisticMessage('temp-1', 'Hello', 'ticket-1');
    expect(store.messages().length).toBe(2);

    const confirmed: Message = {
      id: 'server-id-123',
      ticketId: 'ticket-1',
      content: 'Hello',
      direction: 'outbound',
      sender: 'agent',
      deliveryStatus: 'sent',
      createdAt: '2024-01-01T00:01:00Z',
    };

    store.confirmMessage('temp-1', confirmed);

    // Only the reconciled optimistic entry remains — the earlier echo's
    // duplicate row (tempId !== 'temp-1') is dropped.
    expect(store.messages().length).toBe(1);
    expect(store.messages()[0].id).toBe('server-id-123');
    expect(store.messages()[0].tempId).toBe('temp-1');
  });
});
