import { EventBusService } from './event-bus.service';
import { RealtimeEvent, RealtimeEventType } from '../models/realtime-event.model';

describe('EventBusService', () => {
  let bus: EventBusService;

  beforeEach(() => {
    bus = new EventBusService();
  });

  it('should deliver events to subscribers', () => {
    const events: RealtimeEvent[] = [];
    bus.events$.subscribe(e => events.push(e));

    const event = makeEvent(RealtimeEventType.MessageAdded);
    bus.emit(event);

    expect(events.length).toBe(1);
    expect(events[0]).toBe(event);
  });

  it('should deliver events in FIFO order', () => {
    const events: RealtimeEvent[] = [];
    bus.events$.subscribe(e => events.push(e));

    const e1 = makeEvent(RealtimeEventType.MessageAdded);
    const e2 = makeEvent(RealtimeEventType.TicketCreated);

    bus.emit(e1);
    bus.emit(e2);

    expect(events.length).toBe(2);
    expect(events[0].eventType).toBe(RealtimeEventType.MessageAdded);
    expect(events[1].eventType).toBe(RealtimeEventType.TicketCreated);
  });

  it('should deliver events to multiple subscribers', () => {
    const events1: RealtimeEvent[] = [];
    const events2: RealtimeEvent[] = [];

    bus.events$.subscribe(e => events1.push(e));
    bus.events$.subscribe(e => events2.push(e));

    const event = makeEvent(RealtimeEventType.MessageAdded);
    bus.emit(event);

    expect(events1.length).toBe(1);
    expect(events2.length).toBe(1);
  });

  it('should expose events$ as a readonly Observable (not Subject)', () => {
    // Compile-time: typed as Observable, not Subject
    // Runtime: next() should not exist
    expect((bus.events$ as unknown as { next?: unknown }).next).toBeUndefined();
  });

  function makeEvent(eventType: RealtimeEventType, overrides: Partial<RealtimeEvent> = {}): RealtimeEvent {
    return {
      eventId: 'evt-test-1',
      sequence: 1,
      timestamp: new Date().toISOString(),
      eventType,
      payload: {},
      ...overrides,
    };
  }
});
