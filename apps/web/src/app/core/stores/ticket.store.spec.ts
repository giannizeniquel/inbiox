import { TicketStore, TicketFilter } from './ticket.store';
import { Ticket, TicketStatus } from '../models/ticket.model';

describe('TicketStore', () => {
  let store: TicketStore;

  function makeTicket(overrides: Partial<Ticket> = {}): Ticket {
    return {
      ticket_id: 'ticket-1',
      id: 'ticket-1',
      status: TicketStatus.Active,
      priority: 'medium',
      contact: { id: 1, phone: '+5491112345678', name: 'Test User' },
      last_message: 'Hello',
      owner: null,
      metrics: { unread_count: 0 },
      timestamps: { opened_at: '2024-01-01T00:00:00Z', updated_at: '2024-01-01T00:00:00Z' },
      ...overrides,
    };
  }

  beforeEach(() => {
    store = new TicketStore();
  });

  // ── Initial state ──

  it('should start with empty tickets, mine filter, and not loading', () => {
    expect(store.tickets()).toEqual([]);
    expect(store.activeTicket()).toBeNull();
    expect(store.filter()).toBe('mine');
    expect(store.loading()).toBeFalse();
  });

  // ── loadTickets() ──

  it('should load tickets', () => {
    const tickets = [makeTicket({ ticket_id: 't1', id: 't1' }), makeTicket({ ticket_id: 't2', id: 't2' })];
    store.loadTickets(tickets);
    expect(store.tickets()).toEqual(tickets);
  });

  it('should enforce MAX_TICKETS cap of 1000', () => {
    const tickets = Array.from({ length: 1200 }, (_, i) =>
      makeTicket({ ticket_id: `t-${i}`, id: `t-${i}` })
    );
    store.loadTickets(tickets);
    expect(store.tickets().length).toBe(1000);
    expect(store.tickets()[0].ticket_id).toBe('t-0');
    expect(store.tickets()[999].ticket_id).toBe('t-999');
  });

  // ── addOrUpdateTicket() ──

  it('should add a new ticket at the start', () => {
    const existing = makeTicket({ ticket_id: 't1', id: 't1' });
    store.loadTickets([existing]);

    const newTicket = makeTicket({ ticket_id: 't2', id: 't2' });
    store.addOrUpdateTicket(newTicket);

    expect(store.tickets().length).toBe(2);
    expect(store.tickets()[0].ticket_id).toBe('t2');
    expect(store.tickets()[1].ticket_id).toBe('t1');
  });

  it('should update an existing ticket by ticket_id', () => {
    const original = makeTicket({ ticket_id: 't1', id: 't1', status: TicketStatus.New });
    store.loadTickets([original]);

    const updated = makeTicket({ ticket_id: 't1', id: 't1', status: TicketStatus.Active });
    store.addOrUpdateTicket(updated);

    expect(store.tickets().length).toBe(1);
    expect(store.tickets()[0].status).toBe(TicketStatus.Active);
  });

  // ── setActiveTicket() ──

  it('should set active ticket by id', () => {
    const ticket = makeTicket({ ticket_id: 't1', id: 't1' });
    store.loadTickets([ticket]);

    store.setActiveTicket('t1');
    expect(store.activeTicket()).toEqual(ticket);
  });

  it('should set activeTicket to null when id is not found', () => {
    store.loadTickets([makeTicket({ ticket_id: 't1', id: 't1' })]);

    store.setActiveTicket('nonexistent');
    expect(store.activeTicket()).toBeNull();
  });

  it('should clear active ticket when null is passed', () => {
    const ticket = makeTicket({ ticket_id: 't1', id: 't1' });
    store.loadTickets([ticket]);
    store.setActiveTicket('t1');
    expect(store.activeTicket()).toBeTruthy();

    store.setActiveTicket(null);
    expect(store.activeTicket()).toBeNull();
  });

  // ── setFilter() ──

  it('should switch between filter tabs', () => {
    expect(store.filter()).toBe('mine');

    store.setFilter('waiting');
    expect(store.filter()).toBe('waiting');

    store.setFilter('internal');
    expect(store.filter()).toBe('internal');

    store.setFilter('mine');
    expect(store.filter()).toBe('mine');
  });

  // ── filteredTickets computed ──

  it('should filter "mine" — tickets with owner !== null', () => {
    const owned = makeTicket({ ticket_id: 't1', id: 't1', owner: { id: 1, name: 'Agent' } });
    const unowned = makeTicket({ ticket_id: 't2', id: 't2', owner: null });
    store.loadTickets([owned, unowned]);
    store.setFilter('mine');

    expect(store.filteredTickets().length).toBe(1);
    expect(store.filteredTickets()[0].ticket_id).toBe('t1');
  });

  it('should filter "waiting" — tickets with status === Waiting', () => {
    const waiting = makeTicket({ ticket_id: 't1', id: 't1', status: TicketStatus.Waiting });
    const active = makeTicket({ ticket_id: 't2', id: 't2', status: TicketStatus.Active });
    store.loadTickets([waiting, active]);
    store.setFilter('waiting');

    expect(store.filteredTickets().length).toBe(1);
    expect(store.filteredTickets()[0].ticket_id).toBe('t1');
  });

  it('should filter "internal" — tickets with status New or Active', () => {
    const newT = makeTicket({ ticket_id: 't1', id: 't1', status: TicketStatus.New });
    const activeT = makeTicket({ ticket_id: 't2', id: 't2', status: TicketStatus.Active });
    const waitingT = makeTicket({ ticket_id: 't3', id: 't3', status: TicketStatus.Waiting });
    const resolvedT = makeTicket({ ticket_id: 't4', id: 't4', status: TicketStatus.Resolved });
    store.loadTickets([newT, activeT, waitingT, resolvedT]);
    store.setFilter('internal');

    expect(store.filteredTickets().length).toBe(2);
    const ids = store.filteredTickets().map(t => t.ticket_id);
    expect(ids).toContain('t1');
    expect(ids).toContain('t2');
  });

  // ── filterCounts computed ──

  it('should return counts per filter tab', () => {
    const tickets = [
      makeTicket({ ticket_id: 't1', id: 't1', owner: { id: 1, name: 'A' }, status: TicketStatus.Active }),
      makeTicket({ ticket_id: 't2', id: 't2', owner: null, status: TicketStatus.Waiting }),
      makeTicket({ ticket_id: 't3', id: 't3', owner: null, status: TicketStatus.New }),
      makeTicket({ ticket_id: 't4', id: 't4', owner: { id: 2, name: 'B' }, status: TicketStatus.Resolved }),
    ];
    store.loadTickets(tickets);

    const counts = store.filterCounts();
    expect(counts.mine).toBe(2);      // t1, t4 have owner
    expect(counts.waiting).toBe(1);   // t2
    expect(counts.internal).toBe(2);  // t1 (Active), t3 (New)
  });

  // ── totalUnread computed ──

  it('should sum metrics.unread_count across all tickets', () => {
    const tickets = [
      makeTicket({ ticket_id: 't1', id: 't1', metrics: { unread_count: 3 } }),
      makeTicket({ ticket_id: 't2', id: 't2', metrics: { unread_count: 5 } }),
      makeTicket({ ticket_id: 't3', id: 't3', metrics: { unread_count: 0 } }),
    ];
    store.loadTickets(tickets);

    expect(store.totalUnread()).toBe(8);
  });

  it('should handle tickets with missing metrics gracefully', () => {
    const tickets = [
      makeTicket({ ticket_id: 't1', id: 't1', metrics: { unread_count: 2 } }),
      makeTicket({ ticket_id: 't2', id: 't2', metrics: undefined as unknown as { unread_count: number } }),
    ];
    store.loadTickets(tickets);

    expect(store.totalUnread()).toBe(2);
  });

  // ── updateUnreadCount() ──

  it('should update unread count for a specific ticket', () => {
    store.loadTickets([
      makeTicket({ ticket_id: 't1', id: 't1', metrics: { unread_count: 0 } }),
      makeTicket({ ticket_id: 't2', id: 't2', metrics: { unread_count: 5 } }),
    ]);

    store.updateUnreadCount('t1', 7);

    expect(store.tickets()[0].metrics.unread_count).toBe(7);
    expect(store.tickets()[1].metrics.unread_count).toBe(5);
  });

  // ── markAsRead() ──

  it('should zero the unread count for a ticket', () => {
    store.loadTickets([
      makeTicket({ ticket_id: 't1', id: 't1', metrics: { unread_count: 5 } }),
    ]);

    store.markAsRead('t1');

    expect(store.tickets()[0].metrics.unread_count).toBe(0);
  });

  it('should not affect other tickets when marking as read', () => {
    store.loadTickets([
      makeTicket({ ticket_id: 't1', id: 't1', metrics: { unread_count: 5 } }),
      makeTicket({ ticket_id: 't2', id: 't2', metrics: { unread_count: 3 } }),
    ]);

    store.markAsRead('t1');

    expect(store.tickets()[0].metrics.unread_count).toBe(0);
    expect(store.tickets()[1].metrics.unread_count).toBe(3);
  });

  // ── reset() ──

  it('should clear everything', () => {
    store.loadTickets([makeTicket({ ticket_id: 't1', id: 't1' })]);
    store.setActiveTicket('t1');
    store.setFilter('waiting');
    store.setLoading(true);

    store.reset();

    expect(store.tickets()).toEqual([]);
    expect(store.activeTicket()).toBeNull();
    expect(store.filter()).toBe('mine');
    expect(store.loading()).toBeFalse();
  });

  // ── setLoading() ──

  it('should toggle loading flag', () => {
    expect(store.loading()).toBeFalse();

    store.setLoading(true);
    expect(store.loading()).toBeTrue();

    store.setLoading(false);
    expect(store.loading()).toBeFalse();
  });
});
