import { EventBusService } from './event-bus.service';
import { EventoEnTiempoReal, TipoDeEventoEnTiempoReal } from './evento-en-tiempo-real.model';

describe('EventBusService', () => {
  let bus: EventBusService;

  beforeEach(() => {
    bus = new EventBusService();
  });

  it('should deliver events to subscribers', () => {
    const events: EventoEnTiempoReal[] = [];
    bus.events$.subscribe(e => events.push(e));

    const event = makeEvent(TipoDeEventoEnTiempoReal.MensajeAgregado);
    bus.emit(event);

    expect(events.length).toBe(1);
    expect(events[0]).toBe(event);
  });

  it('should deliver events in FIFO order', () => {
    const events: EventoEnTiempoReal[] = [];
    bus.events$.subscribe(e => events.push(e));

    const e1 = makeEvent(TipoDeEventoEnTiempoReal.MensajeAgregado);
    const e2 = makeEvent(TipoDeEventoEnTiempoReal.TicketCreado);

    bus.emit(e1);
    bus.emit(e2);

    expect(events.length).toBe(2);
    expect(events[0].tipoDeEvento).toBe(TipoDeEventoEnTiempoReal.MensajeAgregado);
    expect(events[1].tipoDeEvento).toBe(TipoDeEventoEnTiempoReal.TicketCreado);
  });

  it('should deliver events to multiple subscribers', () => {
    const events1: EventoEnTiempoReal[] = [];
    const events2: EventoEnTiempoReal[] = [];

    bus.events$.subscribe(e => events1.push(e));
    bus.events$.subscribe(e => events2.push(e));

    const event = makeEvent(TipoDeEventoEnTiempoReal.MensajeAgregado);
    bus.emit(event);

    expect(events1.length).toBe(1);
    expect(events2.length).toBe(1);
  });

  it('should expose events$ as a readonly Observable (not Subject)', () => {
    // Compile-time: typed as Observable, not Subject
    // Runtime: next() should not exist
    expect((bus.events$ as unknown as { next?: unknown }).next).toBeUndefined();
  });

  function makeEvent(eventType: TipoDeEventoEnTiempoReal, overrides: Partial<EventoEnTiempoReal> = {}): EventoEnTiempoReal {
    return {
      eventoId: 'evt-test-1',
      sequence: 1,
      ocurridoAt: new Date().toISOString(),
      tipoDeEvento: eventType,
      payload: {},
      ...overrides,
    };
  }
});
