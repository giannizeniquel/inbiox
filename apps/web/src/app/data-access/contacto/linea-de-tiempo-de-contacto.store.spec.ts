import { TestBed } from '@angular/core/testing';
import { LineaDeTiempoDeContactoStore } from './linea-de-tiempo-de-contacto.store';
import { ContactoApiService } from './contacto-api.service';
import { of, throwError } from 'rxjs';
import { EventoDeLineaDeTiempoDTO, EventoDeLineaDeTiempoResponse } from './linea-de-tiempo-transport.dto';

describe('LineaDeTiempoDeContactoStore', () => {
  let store: LineaDeTiempoDeContactoStore;
  let apiSpy: jasmine.SpyObj<ContactoApiService>;

  const mockEvent = (overrides: Partial<EventoDeLineaDeTiempoDTO> = {}): EventoDeLineaDeTiempoDTO => ({
    type: 'ticket.created',
    occurredAt: '2026-01-01T00:00:00Z',
    ...overrides,
  });

  function mockResponse(events: EventoDeLineaDeTiempoDTO[], hasMore = false, cursor: string | null = null): EventoDeLineaDeTiempoResponse {
    return { events, hasMore, cursor };
  }

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj<ContactoApiService>('ContactoApiService', ['getLineaDeTiempo']);

    TestBed.configureTestingModule({
      providers: [
        LineaDeTiempoDeContactoStore,
        { provide: ContactoApiService, useValue: apiSpy },
      ],
    });

    store = TestBed.inject(LineaDeTiempoDeContactoStore);
  });

  // ── Initial state ──

  it('should start with empty events, not loading, hasMore=true', () => {
    expect(store.events()).toEqual([]);
    expect(store.loading()).toBeFalse();
    expect(store.hasMore()).toBeTrue();
    expect(store.cursor()).toBeNull();
    expect(store.error()).toBeNull();
  });

  // ── loadTimeline() ──

  it('should load timeline and replace events', () => {
    const events = [mockEvent({ type: 'message.received' }), mockEvent({ type: 'ticket.created' })];
    apiSpy.getLineaDeTiempo.and.returnValue(of(mockResponse(events, true, '2026-01-01T00:00:00Z')));

    store.cargarLineaDeTiempo(1);

    expect(store.events().length).toBe(2);
    expect(store.events()[0].type).toBe('message.received');
    expect(store.hasMore()).toBeTrue();
    expect(store.cursor()).toBe('2026-01-01T00:00:00Z');
    expect(store.loading()).toBeFalse();
    expect(store.error()).toBeNull();
  });

  it('should set error on load failure', () => {
    apiSpy.getLineaDeTiempo.and.returnValue(throwError(() => new Error('Network error')));

    store.cargarLineaDeTiempo(1);

    expect(store.error()).toContain('Network error');
    expect(store.loading()).toBeFalse();
    expect(store.events()).toEqual([]);
  });

  // ── loadOlder() ──

  it('should append events when loading older', () => {
    const initialEvents = [mockEvent({ type: 'message.received', occurredAt: '2026-01-02T00:00:00Z' })];
    apiSpy.getLineaDeTiempo.and.returnValues(
      of(mockResponse(initialEvents, true, '2026-01-02T00:00:00Z')),
      of(mockResponse([mockEvent({ type: 'ticket.created', occurredAt: '2026-01-01T00:00:00Z' })], false, null)),
    );

    store.cargarLineaDeTiempo(1);
    expect(store.events().length).toBe(1);

    store.cargarAnteriores(1);

    expect(store.events().length).toBe(2);
    expect(store.events()[0].type).toBe('message.received');
    expect(store.events()[1].type).toBe('ticket.created');
    expect(store.hasMore()).toBeFalse();
    expect(store.cursor()).toBeNull();
  });

  it('should not load older when hasMore is false', () => {
    apiSpy.getLineaDeTiempo.and.returnValue(of(mockResponse([], false, null)));
    store.cargarLineaDeTiempo(1);

    apiSpy.getLineaDeTiempo.calls.reset();

    store.cargarAnteriores(1);

    expect(apiSpy.getLineaDeTiempo).not.toHaveBeenCalled();
  });

  it('should set error on loadOlder failure', () => {
    apiSpy.getLineaDeTiempo.and.returnValues(
      of(mockResponse([mockEvent()], true, '2026-01-01T00:00:00Z')),
      throwError(() => new Error('Server error')),
    );

    store.cargarLineaDeTiempo(1);
    store.cargarAnteriores(1);

    expect(store.error()).toContain('Server error');
    expect(store.loading()).toBeFalse();
  });

  // ── reset() ──

  it('should clear all state on reset', () => {
    apiSpy.getLineaDeTiempo.and.returnValue(of(mockResponse([mockEvent()])));

    store.cargarLineaDeTiempo(1);
    expect(store.events().length).toBe(1);

    store.reset();

    expect(store.events()).toEqual([]);
    expect(store.loading()).toBeFalse();
    expect(store.hasMore()).toBeTrue();
    expect(store.cursor()).toBeNull();
    expect(store.error()).toBeNull();
  });
});
