import { TestBed } from '@angular/core/testing';
import { ContactTimelineStore } from './contact-timeline.store';
import { ContactsApiService } from './contacts-api.service';
import { of, throwError } from 'rxjs';
import { TimelineEventDTO, TimelineEventResponse } from './timeline-transport.dto';

describe('ContactTimelineStore', () => {
  let store: ContactTimelineStore;
  let apiSpy: jasmine.SpyObj<ContactsApiService>;

  const mockEvent = (overrides: Partial<TimelineEventDTO> = {}): TimelineEventDTO => ({
    type: 'ticket.created',
    occurredAt: '2026-01-01T00:00:00Z',
    ...overrides,
  });

  function mockResponse(events: TimelineEventDTO[], hasMore = false, cursor: string | null = null): TimelineEventResponse {
    return { events, hasMore, cursor };
  }

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj<ContactsApiService>('ContactsApiService', ['getTimeline']);

    TestBed.configureTestingModule({
      providers: [
        ContactTimelineStore,
        { provide: ContactsApiService, useValue: apiSpy },
      ],
    });

    store = TestBed.inject(ContactTimelineStore);
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
    apiSpy.getTimeline.and.returnValue(of(mockResponse(events, true, '2026-01-01T00:00:00Z')));

    store.loadTimeline(1);

    expect(store.events().length).toBe(2);
    expect(store.events()[0].type).toBe('message.received');
    expect(store.hasMore()).toBeTrue();
    expect(store.cursor()).toBe('2026-01-01T00:00:00Z');
    expect(store.loading()).toBeFalse();
    expect(store.error()).toBeNull();
  });

  it('should set error on load failure', () => {
    apiSpy.getTimeline.and.returnValue(throwError(() => new Error('Network error')));

    store.loadTimeline(1);

    expect(store.error()).toContain('Network error');
    expect(store.loading()).toBeFalse();
    expect(store.events()).toEqual([]);
  });

  // ── loadOlder() ──

  it('should append events when loading older', () => {
    const initialEvents = [mockEvent({ type: 'message.received', occurredAt: '2026-01-02T00:00:00Z' })];
    apiSpy.getTimeline.and.returnValues(
      of(mockResponse(initialEvents, true, '2026-01-02T00:00:00Z')),
      of(mockResponse([mockEvent({ type: 'ticket.created', occurredAt: '2026-01-01T00:00:00Z' })], false, null)),
    );

    store.loadTimeline(1);
    expect(store.events().length).toBe(1);

    store.loadOlder(1);

    expect(store.events().length).toBe(2);
    expect(store.events()[0].type).toBe('message.received');
    expect(store.events()[1].type).toBe('ticket.created');
    expect(store.hasMore()).toBeFalse();
    expect(store.cursor()).toBeNull();
  });

  it('should not load older when hasMore is false', () => {
    apiSpy.getTimeline.and.returnValue(of(mockResponse([], false, null)));
    store.loadTimeline(1);

    apiSpy.getTimeline.calls.reset();

    store.loadOlder(1);

    expect(apiSpy.getTimeline).not.toHaveBeenCalled();
  });

  it('should set error on loadOlder failure', () => {
    apiSpy.getTimeline.and.returnValues(
      of(mockResponse([mockEvent()], true, '2026-01-01T00:00:00Z')),
      throwError(() => new Error('Server error')),
    );

    store.loadTimeline(1);
    store.loadOlder(1);

    expect(store.error()).toContain('Server error');
    expect(store.loading()).toBeFalse();
  });

  // ── reset() ──

  it('should clear all state on reset', () => {
    apiSpy.getTimeline.and.returnValue(of(mockResponse([mockEvent()])));

    store.loadTimeline(1);
    expect(store.events().length).toBe(1);

    store.reset();

    expect(store.events()).toEqual([]);
    expect(store.loading()).toBeFalse();
    expect(store.hasMore()).toBeTrue();
    expect(store.cursor()).toBeNull();
    expect(store.error()).toBeNull();
  });
});
