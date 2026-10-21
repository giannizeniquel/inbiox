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
    apiSpy = jasmine.createSpyObj('ReporteApiService', ['getParticipacionDeTicket']);

    await TestBed.configureTestingModule({
      imports: [ParticipacionDeTicketComponent],
      providers: [{ provide: ReporteApiService, useValue: apiSpy }],
    }).compileComponents();

    fixture = TestBed.createComponent(ParticipacionDeTicketComponent);
  });

  it('lists participants with message counts and average delay; automatic activity is count-only', () => {
    const participacion: ParticipacionDeTicket = {
      ticketId: 42,
      datosDisponiblesDesde: '2026-01-01T00:00:00Z',
      datosParciales: false,
      participantes: [
        { agenteId: 1, agenteNombre: 'Ana', cantidadDeMensajes: 3, cantidadDeMuestras: 2, demoraPromedioSegundos: 90 },
        { agenteId: 2, agenteNombre: 'Beto', cantidadDeMensajes: 1, cantidadDeMuestras: 1, demoraPromedioSegundos: 30 },
      ],
      cantidadDeMensajesAutomaticos: 2,
      cantidadDeEntrantesSinResponder: 0,
      pares: [],
    };
    apiSpy.getParticipacionDeTicket.and.returnValue(of(participacion));

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
    const participacion: ParticipacionDeTicket = {
      ticketId: 42,
      datosDisponiblesDesde: '2026-02-01T00:00:00Z',
      datosParciales: true,
      participantes: [],
      cantidadDeMensajesAutomaticos: 0,
      cantidadDeEntrantesSinResponder: 0,
      pares: [],
    };
    apiSpy.getParticipacionDeTicket.and.returnValue(of(participacion));

    fixture.componentRef.setInput('ticketId', 42);
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('Sin datos de atribución');
  });

  it('refetches when ticketId changes', () => {
    apiSpy.getParticipacionDeTicket.and.returnValue(of({
      ticketId: 1,
      datosDisponiblesDesde: null,
      datosParciales: false,
      participantes: [],
      cantidadDeMensajesAutomaticos: 0,
      cantidadDeEntrantesSinResponder: 0,
      pares: [],
    } as ParticipacionDeTicket));

    fixture.componentRef.setInput('ticketId', 1);
    fixture.detectChanges();
    expect(apiSpy.getParticipacionDeTicket).toHaveBeenCalledWith(1);

    fixture.componentRef.setInput('ticketId', 2);
    fixture.detectChanges();
    expect(apiSpy.getParticipacionDeTicket).toHaveBeenCalledWith(2);
  });
});
