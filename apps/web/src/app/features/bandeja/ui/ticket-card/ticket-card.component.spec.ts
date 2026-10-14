import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TicketCardComponent } from './ticket-card.component';
import { Ticket, TicketStatus } from '../../../../data-access/ticket/ticket.model';
import { AgentAvailabilityDTO } from '../../../../data-access/disponibilidad/agent-availability.dto';

function makeTicket(overrides: Partial<Ticket> = {}): Ticket {
  return {
    ticket_id: 't-1',
    id: 't-1',
    status: TicketStatus.New,
    priority: 'normal',
    contact: { id: 1, phone: '+1234567890', name: 'John Doe' },
    last_message: 'Hello',
    owner: null,
    metrics: { unread_count: 0 },
    timestamps: { opened_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' },
    ...overrides,
  };
}

function makeAgent(overrides: Partial<AgentAvailabilityDTO> = {}): AgentAvailabilityDTO {
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
    availableAgents?: AgentAvailabilityDTO[];
    canAssign?: boolean;
  } = {}): Promise<void> {
    fixture = TestBed.createComponent(TicketCardComponent);
    fixture.componentRef.setInput('ticket', inputs.ticket ?? makeTicket());
    fixture.componentRef.setInput('isActive', inputs.isActive ?? false);
    fixture.componentRef.setInput('availableAgents', inputs.availableAgents ?? []);
    fixture.componentRef.setInput('canAssign', inputs.canAssign ?? true);
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
      await createComponent({ availableAgents: [], canAssign: true });

      expect(component.showDropdown()).toBe(true);
    });

    it('is visible when the roster has zero Activo agents (all Inactivo)', async () => {
      await createComponent({
        availableAgents: [
          makeAgent({ id: 1, is_active_now: false }),
          makeAgent({ id: 2, is_active_now: false }),
        ],
        canAssign: true,
      });

      expect(component.showDropdown()).toBe(true);
    });

    it('is hidden when the ticket is not assignable, regardless of roster', async () => {
      await createComponent({
        availableAgents: [makeAgent({ is_active_now: true })],
        canAssign: false,
      });

      expect(component.showDropdown()).toBe(false);
    });

    it('is hidden when the ticket status is not assignable (resolved)', async () => {
      await createComponent({
        ticket: makeTicket({ status: TicketStatus.Resolved }),
        availableAgents: [makeAgent({ is_active_now: true })],
        canAssign: true,
      });

      expect(component.showDropdown()).toBe(false);
    });
  });

  describe('activeAgents() / inactiveAgents() grouping', () => {
    it('splits the roster into Activo and Inactivo groups', async () => {
      const activo = makeAgent({ id: 1, is_active_now: true });
      const inactivo = makeAgent({ id: 2, is_active_now: false });

      await createComponent({ availableAgents: [inactivo, activo] });

      expect(component.activeAgents()).toEqual([activo]);
      expect(component.inactiveAgents()).toEqual([inactivo]);
    });

    it('renders Activo group before Inactivo group in the dropdown DOM', async () => {
      const activo = makeAgent({ id: 1, name: 'Activo Agent', is_active_now: true });
      const inactivo = makeAgent({ id: 2, name: 'Inactivo Agent', is_active_now: false });

      await createComponent({ availableAgents: [inactivo, activo] });
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
      const activo = makeAgent({ id: 1, is_active_now: true });
      await createComponent({ availableAgents: [activo] });

      let emitted: { ticketId: string; agentId: number } | undefined;
      component.assign.subscribe((e) => (emitted = e));

      component.selectAgent(activo, new MouseEvent('click'));

      expect(emitted).toEqual({ ticketId: 't-1', agentId: 1 });
    });

    it('does NOT emit assign when selecting an Inactivo agent', async () => {
      const inactivo = makeAgent({ id: 2, is_active_now: false });
      await createComponent({ availableAgents: [inactivo] });

      let emitted: { ticketId: string; agentId: number } | undefined;
      component.assign.subscribe((e) => (emitted = e));

      component.selectAgent(inactivo, new MouseEvent('click'));

      expect(emitted).toBeUndefined();
    });

    it('marks the Inactivo row as disabled in the DOM', async () => {
      const inactivo = makeAgent({ id: 2, name: 'Inactivo Agent', is_active_now: false });
      await createComponent({ availableAgents: [inactivo] });
      component.toggleDropdown(new MouseEvent('click'));
      fixture.detectChanges();

      const row = fixture.nativeElement.querySelector('.ticket-card__dropdown-item');
      expect(row.disabled).toBe(true);
    });
  });
});
