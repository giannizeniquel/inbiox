import { TicketStore, FiltroDeTicket } from './ticket.store';
import { Ticket, EstadoDeTicket } from './ticket.model';
import { EventoEnTiempoReal, TipoDeEventoEnTiempoReal } from '../../core/tiempo-real/evento-en-tiempo-real.model';
import { EspacioDeTrabajoStore } from '../administracion/espacio-de-trabajo.store';
import { applyTicketEvent } from './ticket-realtime.reducer';

describe('TicketStore', () => {
  let store: TicketStore;

  function makeTicket(overrides: Partial<Ticket> = {}): Ticket {
    return {
      ticketId: 'ticket-1',
      id: 'ticket-1',
      estado: EstadoDeTicket.Activo,
      prioridad: 'media',
      contacto: { id: 1, telefono: '+5491112345678', nombre: 'Test User' },
      ultimoMensaje: 'Hello',
      responsable: null,
      metricas: { cantidadDeNoLeidos: 0 },
      fechas: { abiertoAt: '2024-01-01T00:00:00Z', actualizadoAt: '2024-01-01T00:00:00Z' },
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
    expect(store.filtro()).toBe('mine');
    expect(store.loading()).toBeFalse();
  });

  // ── loadTickets() ──

  it('should load tickets', () => {
    const tickets = [makeTicket({ ticketId: 't1', id: 't1' }), makeTicket({ ticketId: 't2', id: 't2' })];
    store.cargarTickets(tickets);
    expect(store.tickets()).toEqual(tickets);
  });

  it('should enforce MAX_TICKETS cap of 1000', () => {
    const tickets = Array.from({ length: 1200 }, (_, i) =>
      makeTicket({ ticketId: `t-${i}`, id: `t-${i}` })
    );
    store.cargarTickets(tickets);
    expect(store.tickets().length).toBe(1000);
    expect(store.tickets()[0].ticketId).toBe('t-0');
    expect(store.tickets()[999].ticketId).toBe('t-999');
  });

  // ── addOrUpdateTicket() ──

  it('should add a new ticket at the start', () => {
    const existing = makeTicket({ ticketId: 't1', id: 't1' });
    store.cargarTickets([existing]);

    const newTicket = makeTicket({ ticketId: 't2', id: 't2' });
    store.addOrUpdateTicket(newTicket);

    expect(store.tickets().length).toBe(2);
    expect(store.tickets()[0].ticketId).toBe('t2');
    expect(store.tickets()[1].ticketId).toBe('t1');
  });

  it('should update an existing ticket by ticketId', () => {
    const original = makeTicket({ ticketId: 't1', id: 't1', estado: EstadoDeTicket.Nuevo });
    store.cargarTickets([original]);

    const updated = makeTicket({ ticketId: 't1', id: 't1', estado: EstadoDeTicket.Activo });
    store.addOrUpdateTicket(updated);

    expect(store.tickets().length).toBe(1);
    expect(store.tickets()[0].estado).toBe(EstadoDeTicket.Activo);
  });

  // ── setActiveTicket() ──

  it('should set active ticket by id', () => {
    const ticket = makeTicket({ ticketId: 't1', id: 't1' });
    store.cargarTickets([ticket]);

    store.setActiveTicket('t1');
    expect(store.activeTicket()).toEqual(ticket);
  });

  it('should set activeTicket to null when id is not found', () => {
    store.cargarTickets([makeTicket({ ticketId: 't1', id: 't1' })]);

    store.setActiveTicket('nonexistent');
    expect(store.activeTicket()).toBeNull();
  });

  it('should clear active ticket when null is passed', () => {
    const ticket = makeTicket({ ticketId: 't1', id: 't1' });
    store.cargarTickets([ticket]);
    store.setActiveTicket('t1');
    expect(store.activeTicket()).toBeTruthy();

    store.setActiveTicket(null);
    expect(store.activeTicket()).toBeNull();
  });

  // ── setFilter() ──

  it('should switch between filter tabs', () => {
    expect(store.filtro()).toBe('mine');

    store.setFiltro('waiting');
    expect(store.filtro()).toBe('waiting');

    store.setFiltro('internal');
    expect(store.filtro()).toBe('internal');

    store.setFiltro('mine');
    expect(store.filtro()).toBe('mine');
  });

  // ── filteredTickets computed ──

  it('should filter "mine" — tickets with owner !== null', () => {
    const owned = makeTicket({ ticketId: 't1', id: 't1', responsable: { id: 1, nombre: 'Agent' } });
    const unowned = makeTicket({ ticketId: 't2', id: 't2', responsable: null });
    store.cargarTickets([owned, unowned]);
    store.setFiltro('mine');

    expect(store.filteredTickets().length).toBe(1);
    expect(store.filteredTickets()[0].ticketId).toBe('t1');
  });

  it('should filter "waiting" — tickets with no owner (unassigned)', () => {
    const sinAsignar = makeTicket({ ticketId: 't1', id: 't1', responsable: null });
    const asignado = makeTicket({ ticketId: 't2', id: 't2', responsable: { id: 1, nombre: 'Agent' } });
    store.cargarTickets([sinAsignar, asignado]);
    store.setFiltro('waiting');

    expect(store.filteredTickets().length).toBe(1);
    expect(store.filteredTickets()[0].ticketId).toBe('t1');
  });

  it('should filter "internal" — all tickets', () => {
    const newT = makeTicket({ ticketId: 't1', id: 't1', estado: EstadoDeTicket.Nuevo });
    const ticketResuelto = makeTicket({ ticketId: 't2', id: 't2', estado: EstadoDeTicket.Resuelto });
    store.cargarTickets([newT, ticketResuelto]);
    store.setFiltro('internal');

    expect(store.filteredTickets().length).toBe(2);
  });

  // ── filterCounts computed ──

  it('should return counts per filter tab', () => {
    const tickets = [
      makeTicket({ ticketId: 't1', id: 't1', responsable: { id: 1, nombre: 'A' }, estado: EstadoDeTicket.Activo }),
      makeTicket({ ticketId: 't2', id: 't2', responsable: null, estado: EstadoDeTicket.EnEspera }),
      makeTicket({ ticketId: 't3', id: 't3', responsable: null, estado: EstadoDeTicket.Nuevo }),
      makeTicket({ ticketId: 't4', id: 't4', responsable: { id: 2, nombre: 'B' }, estado: EstadoDeTicket.Resuelto }),
    ];
    store.cargarTickets(tickets);

    const conteos = store.conteosDeFiltro();
    expect(conteos.mine).toBe(2);      // t1, t4 have owner
    expect(conteos.waiting).toBe(2);   // t2, t3 have no owner (unassigned)
    expect(conteos.internal).toBe(4);  // all tickets
  });

  // ── totalUnread computed ──

  it('should sum metricas.cantidadDeNoLeidos across all tickets', () => {
    const tickets = [
      makeTicket({ ticketId: 't1', id: 't1', metricas: { cantidadDeNoLeidos: 3 } }),
      makeTicket({ ticketId: 't2', id: 't2', metricas: { cantidadDeNoLeidos: 5 } }),
      makeTicket({ ticketId: 't3', id: 't3', metricas: { cantidadDeNoLeidos: 0 } }),
    ];
    store.cargarTickets(tickets);

    expect(store.totalNoLeidos()).toBe(8);
  });

  it('should handle tickets with missing metrics gracefully', () => {
    const tickets = [
      makeTicket({ ticketId: 't1', id: 't1', metricas: { cantidadDeNoLeidos: 2 } }),
      makeTicket({ ticketId: 't2', id: 't2', metricas: undefined as unknown as { cantidadDeNoLeidos: number } }),
    ];
    store.cargarTickets(tickets);

    expect(store.totalNoLeidos()).toBe(2);
  });

  // ── updateUnreadCount() ──

  it('should update unread count for a specific ticket', () => {
    store.cargarTickets([
      makeTicket({ ticketId: 't1', id: 't1', metricas: { cantidadDeNoLeidos: 0 } }),
      makeTicket({ ticketId: 't2', id: 't2', metricas: { cantidadDeNoLeidos: 5 } }),
    ]);

    store.updateConteoDeNoLeidos('t1', 7);

    expect(store.tickets()[0].metricas.cantidadDeNoLeidos).toBe(7);
    expect(store.tickets()[1].metricas.cantidadDeNoLeidos).toBe(5);
  });

  // ── markAsRead() ──

  it('should zero the unread count for a ticket', () => {
    store.cargarTickets([
      makeTicket({ ticketId: 't1', id: 't1', metricas: { cantidadDeNoLeidos: 5 } }),
    ]);

    store.marcarComoLeido('t1');

    expect(store.tickets()[0].metricas.cantidadDeNoLeidos).toBe(0);
  });

  it('should not affect other tickets when marking as read', () => {
    store.cargarTickets([
      makeTicket({ ticketId: 't1', id: 't1', metricas: { cantidadDeNoLeidos: 5 } }),
      makeTicket({ ticketId: 't2', id: 't2', metricas: { cantidadDeNoLeidos: 3 } }),
    ]);

    store.marcarComoLeido('t1');

    expect(store.tickets()[0].metricas.cantidadDeNoLeidos).toBe(0);
    expect(store.tickets()[1].metricas.cantidadDeNoLeidos).toBe(3);
  });

  // ── reset() ──

  it('should clear everything', () => {
    store.cargarTickets([makeTicket({ ticketId: 't1', id: 't1' })]);
    store.setActiveTicket('t1');
    store.setFiltro('waiting');
    store.setLoading(true);

    store.reset();

    expect(store.tickets()).toEqual([]);
    expect(store.activeTicket()).toBeNull();
    expect(store.filtro()).toBe('mine');
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

  // ── optimisticAssign() ──

  it('should optimistically update ticket owner', () => {
    const ticket = makeTicket({ ticketId: 't1', id: 't1', responsable: { id: 1, nombre: 'Agent A' } });
    store.cargarTickets([ticket]);

    const responsableAnterior = store.asignarOptimista('t1', 2, 'Agent B');

    expect(responsableAnterior).toEqual({ id: 1, nombre: 'Agent A' });
    expect(store.tickets()[0].responsable).toEqual({ id: 2, nombre: 'Agent B' });
  });

  it('should return null when ticket not found', () => {
    const responsableAnterior = store.asignarOptimista('nonexistent', 2, 'Agent B');

    expect(responsableAnterior).toBeNull();
  });

  // ── rollbackAssign() ──

  it('should rollback to previous owner', () => {
    const ticket = makeTicket({ ticketId: 't1', id: 't1', responsable: { id: 1, nombre: 'Agent A' } });
    store.cargarTickets([ticket]);

    store.asignarOptimista('t1', 2, 'Agent B');
    store.revertirAsignacion('t1', { id: 1, nombre: 'Agent A' });

    expect(store.tickets()[0].responsable).toEqual({ id: 1, nombre: 'Agent A' });
  });

  it('should handle rollback with null previous owner', () => {
    const ticket = makeTicket({ ticketId: 't1', id: 't1', responsable: null });
    store.cargarTickets([ticket]);

    store.asignarOptimista('t1', 2, 'Agent B');
    store.revertirAsignacion('t1', null);

    expect(store.tickets()[0].responsable).toBeNull();
  });

  // ── assignmentError() ──

  it('should record assignment error', () => {
    store.errorDeAsignacion('t1', 'Assignment failed');

    const errors = store.erroresDeAsignacion();
    expect(errors.has(1)).toBeTrue();
    expect(errors.get(1)?.message).toBe('Assignment failed');
    expect(errors.get(1)?.until).toBeGreaterThan(Date.now());
  });

  it('should auto-dismiss error after 5 seconds', (done) => {
    jasmine.clock().install();
    store.errorDeAsignacion('t1', 'Assignment failed');

    expect(store.erroresDeAsignacion().has(1)).toBeTrue();

    jasmine.clock().tick(5001);

    expect(store.erroresDeAsignacion().has(1)).toBeFalse();

    jasmine.clock().uninstall();
    done();
  });

  // ── reset() clears assignment state ──

  it('should clear assignment state on reset', () => {
    const ticket = makeTicket({ ticketId: 't1', id: 't1' });
    store.cargarTickets([ticket]);
    store.asignarOptimista('t1', 2, 'Agent B');
    store.errorDeAsignacion('t1', 'Error');

    store.reset();

    expect(store.erroresDeAsignacion().size).toBe(0);
  });

  // ── applyTicketEvent(MessageAdded) direction guard (message-agent-attribution, design.md D6) ──
  // Outbound messages now broadcast too (human-agent replies) — an agent's
  // own reply (or a colleague's) on a non-active ticket must never inflate
  // the unread badge; only genuine inbound (contact) messages should.

  // Test tickets use string ids ('t1'), so the payload is cast to the wire type.
  function armarEventoDeMensajeAgregado(direccion: 'entrante' | 'saliente', ticketId = 't1'): EventoEnTiempoReal {
    return {
      eventoId: 'evt-1',
      tipoDeEvento: TipoDeEventoEnTiempoReal.MensajeAgregado,
      ticketId,
      payload: {
        mensajeId: 1,
        ticketId,
        contenido: 'Hi',
        direccion: direccion,
        remitenteNombre: null,
        creadoAt: '2024-01-01T00:00:00Z',
        externalId: null,
      },
    } as unknown as EventoEnTiempoReal;
  }

  it('should skip the unread bump for an outbound MessageAdded on a non-active ticket', () => {
    store.cargarTickets([makeTicket({ ticketId: 't1', id: 't1', metricas: { cantidadDeNoLeidos: 0 } })]);
    store.setActiveTicket(null); // no active ticket — t1 is not active

    applyTicketEvent(armarEventoDeMensajeAgregado('saliente'), store, {} as EspacioDeTrabajoStore);

    expect(store.tickets()[0].metricas.cantidadDeNoLeidos).toBe(0);
  });

  it('should bump unread for an inbound MessageAdded on a non-active ticket', () => {
    store.cargarTickets([makeTicket({ ticketId: 't1', id: 't1', metricas: { cantidadDeNoLeidos: 0 } })]);
    store.setActiveTicket(null);

    applyTicketEvent(armarEventoDeMensajeAgregado('entrante'), store, {} as EspacioDeTrabajoStore);

    expect(store.tickets()[0].metricas.cantidadDeNoLeidos).toBe(1);
  });
});
