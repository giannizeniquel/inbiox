import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TicketCardComponent } from './ticket-card.component';
import { Ticket, EstadoDeTicket } from '../../../../data-access/ticket/ticket.model';
import { DisponibilidadDeAgenteDTO } from '../../../../data-access/disponibilidad/disponibilidad-de-agente.dto';

function makeTicket(overrides: Partial<Ticket> = {}): Ticket {
  return {
    ticket_id: 't-1',
    id: 't-1',
    status: EstadoDeTicket.Nuevo,
    priority: 'normal',
    contact: { id: 1, phone: '+1234567890', name: 'John Doe' },
    last_message: 'Hello',
    owner: null,
    metrics: { unread_count: 0 },
    timestamps: { opened_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' },
    ...overrides,
  };
}

function armarAgente(overrides: Partial<DisponibilidadDeAgenteDTO> = {}): DisponibilidadDeAgenteDTO {
  return {
    id: 1,
    name: 'Agent 1',
    is_active_now: true,
    active_ticket_count: 0,
    max_active_tickets: 5,
    ...overrides,
  };
}

describe('TicketCardComponent', () => {
  let fixture: ComponentFixture<TicketCardComponent>;
  let component: TicketCardComponent;

  async function createComponent(inputs: {
    ticket?: Ticket;
    isActive?: boolean;
    agentesDisponibles?: DisponibilidadDeAgenteDTO[];
    puedeAsignar?: boolean;
  } = {}): Promise<void> {
    fixture = TestBed.createComponent(TicketCardComponent);
    fixture.componentRef.setInput('ticket', inputs.ticket ?? makeTicket());
    fixture.componentRef.setInput('isActive', inputs.isActive ?? false);
    fixture.componentRef.setInput('agentesDisponibles', inputs.agentesDisponibles ?? []);
    fixture.componentRef.setInput('puedeAsignar', inputs.puedeAsignar ?? true);
    fixture.detectChanges();
    component = fixture.componentInstance;
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [TicketCardComponent],
    }).compileComponents();
  });

  describe('showDropdown — no agents.length gate', () => {
    it('is visible when assignable even with zero agents in the roster', async () => {
      await createComponent({ agentesDisponibles: [], puedeAsignar: true });

      expect(component.showDropdown()).toBe(true);
    });

    it('is visible when the roster has zero Activo agents (all Inactivo)', async () => {
      await createComponent({
        agentesDisponibles: [
          armarAgente({ id: 1, is_active_now: false }),
          armarAgente({ id: 2, is_active_now: false }),
        ],
        puedeAsignar: true,
      });

      expect(component.showDropdown()).toBe(true);
    });

    it('is hidden when the ticket is not assignable, regardless of roster', async () => {
      await createComponent({
        agentesDisponibles: [armarAgente({ is_active_now: true })],
        puedeAsignar: false,
      });

      expect(component.showDropdown()).toBe(false);
    });

    it('is hidden when the ticket status is not assignable (resolved)', async () => {
      await createComponent({
        ticket: makeTicket({ status: EstadoDeTicket.Resuelto }),
        agentesDisponibles: [armarAgente({ is_active_now: true })],
        puedeAsignar: true,
      });

      expect(component.showDropdown()).toBe(false);
    });
  });

  describe('activeAgents() / inactiveAgents() grouping', () => {
    it('splits the roster into Activo and Inactivo groups', async () => {
      const activo = armarAgente({ id: 1, is_active_now: true });
      const inactivo = armarAgente({ id: 2, is_active_now: false });

      await createComponent({ agentesDisponibles: [inactivo, activo] });

      expect(component.agentesActivos()).toEqual([activo]);
      expect(component.agentesInactivos()).toEqual([inactivo]);
    });

    it('renders Activo group before Inactivo group in the dropdown DOM', async () => {
      const activo = armarAgente({ id: 1, name: 'Activo Agent', is_active_now: true });
      const inactivo = armarAgente({ id: 2, name: 'Inactivo Agent', is_active_now: false });

      await createComponent({ agentesDisponibles: [inactivo, activo] });
      component.toggleDropdown(new MouseEvent('click'));
      fixture.detectChanges();

      const names = Array.from(
        fixture.nativeElement.querySelectorAll('.ticket-card__dropdown-name'),
      ).map((el: any) => el.textContent.trim());

      expect(names).toEqual(['Activo Agent', 'Inactivo Agent']);
    });
  });

  describe('selectAgent() — Inactivo rows are not selectable', () => {
    it('emits assign when selecting an Activo agent', async () => {
      const activo = armarAgente({ id: 1, is_active_now: true });
      await createComponent({ agentesDisponibles: [activo] });

      let emitted: { ticketId: string; agentId: number } | undefined;
      component.asignar.subscribe((e) => (emitted = e));

      component.seleccionarAgente(activo, new MouseEvent('click'));

      expect(emitted).toEqual({ ticketId: 't-1', agentId: 1 });
    });

    it('does NOT emit assign when selecting an Inactivo agent', async () => {
      const inactivo = armarAgente({ id: 2, is_active_now: false });
      await createComponent({ agentesDisponibles: [inactivo] });

      let emitted: { ticketId: string; agentId: number } | undefined;
      component.asignar.subscribe((e) => (emitted = e));

      component.seleccionarAgente(inactivo, new MouseEvent('click'));

      expect(emitted).toBeUndefined();
    });

    it('marks the Inactivo row as disabled in the DOM', async () => {
      const inactivo = armarAgente({ id: 2, name: 'Inactivo Agent', is_active_now: false });
      await createComponent({ agentesDisponibles: [inactivo] });
      component.toggleDropdown(new MouseEvent('click'));
      fixture.detectChanges();

      const row = fixture.nativeElement.querySelector('.ticket-card__dropdown-item');
      expect(row.disabled).toBe(true);
    });
  });
});
