import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { ParticipacionDeTicketComponent } from './participacion-de-ticket.component';
import { ReporteApiService } from '../../../../data-access/reporte/reporte-api.service';
import { ParticipacionDeTicket } from '../../../../data-access/reporte/demora-de-respuesta.dto';

/**
 * NOTE: Karma/Jasmine cannot run in this WSL2 environment (no Chrome).
 * Written for documentation / future-run value; `ng build --configuration
 * production` is the enforceable frontend gate for this change.
 */
describe('ParticipacionDeTicketComponent', () => {
  let fixture: ComponentFixture<ParticipacionDeTicketComponent>;
  let apiSpy: jasmine.SpyObj<ReporteApiService>;

  beforeEach(async () => {
    apiSpy = jasmine.createSpyObj('ReporteApiService', ['getTicketParticipation']);

    await TestBed.configureTestingModule({
      imports: [ParticipacionDeTicketComponent],
      providers: [{ provide: ReporteApiService, useValue: apiSpy }],
    }).compileComponents();

    fixture = TestBed.createComponent(ParticipacionDeTicketComponent);
  });

  it('lists participants with message counts and average delay; automatic activity is count-only', () => {
    const participation: ParticipacionDeTicket = {
      ticket_id: 42,
      data_available_from: '2026-01-01T00:00:00Z',
      partial_data: false,
      participants: [
        { agent_id: 1, agent_name: 'Ana', message_count: 3, sample_count: 2, avg_delay_seconds: 90 },
        { agent_id: 2, agent_name: 'Beto', message_count: 1, sample_count: 1, avg_delay_seconds: 30 },
      ],
      automatic_message_count: 2,
      unanswered_inbound_count: 0,
      pairs: [],
    };
    apiSpy.getTicketParticipation.and.returnValue(of(participation));

    fixture.componentRef.setInput('ticketId', 42);
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('Ana');
    expect(text).toContain('Beto');
    expect(text).toContain('Actividad automática: 2');
    // Automatic activity must never appear as a participant row.
    expect(text).not.toContain('automatic');
  });

  it('shows "sin datos de atribución" instead of "0" when partial_data is true', () => {
    const participation: ParticipacionDeTicket = {
      ticket_id: 42,
      data_available_from: '2026-02-01T00:00:00Z',
      partial_data: true,
      participants: [],
      automatic_message_count: 0,
      unanswered_inbound_count: 0,
      pairs: [],
    };
    apiSpy.getTicketParticipation.and.returnValue(of(participation));

    fixture.componentRef.setInput('ticketId', 42);
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('Sin datos de atribución');
  });

  it('refetches when ticketId changes', () => {
    apiSpy.getTicketParticipation.and.returnValue(of({
      ticket_id: 1,
      data_available_from: null,
      partial_data: false,
      participants: [],
      automatic_message_count: 0,
      unanswered_inbound_count: 0,
      pairs: [],
    } as ParticipacionDeTicket));

    fixture.componentRef.setInput('ticketId', 1);
    fixture.detectChanges();
    expect(apiSpy.getTicketParticipation).toHaveBeenCalledWith(1);

    fixture.componentRef.setInput('ticketId', 2);
    fixture.detectChanges();
    expect(apiSpy.getTicketParticipation).toHaveBeenCalledWith(2);
  });
});
