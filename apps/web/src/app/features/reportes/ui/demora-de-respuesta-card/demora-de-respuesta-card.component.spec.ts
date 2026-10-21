import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { DemoraDeRespuestaCardComponent } from './demora-de-respuesta-card.component';
import { ReporteDeDemoraDeRespuesta } from '../../../../data-access/reporte/demora-de-respuesta.dto';

/**
 * DemoraDeRespuestaCardComponent tests — documents the never-zero / partial-data
 * rendering contract from spec.md "Reports Page Delay Surface".
 *
 * NOTE: Karma/Jasmine cannot run in this WSL2 environment (no Chrome).
 * Written for documentation / future-run value; `ng build --configuration
 * production` is the enforceable frontend gate for this change.
 */
describe('DemoraDeRespuestaCardComponent', () => {
  let fixture: ComponentFixture<DemoraDeRespuestaCardComponent>;
  let component: DemoraDeRespuestaCardComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DemoraDeRespuestaCardComponent],
    }).compileComponents();

    fixture = TestBed.createComponent(DemoraDeRespuestaCardComponent);
    component = fixture.componentInstance;
  });

  it('shows the "sin datos de atribución" notice on partial_data, never a zero value', () => {
    const partialData: ReporteDeDemoraDeRespuesta = {
      datosDisponiblesDesde: '2026-06-01T00:00:00Z',
      datosParciales: true,
      global: { demoraPromedioSegundos: 0, cantidadDeMuestras: 0, cantidadDeEntrantesSinResponder: 0 },
      porAgente: [],
    };
    component.data = partialData;
    component.loading = false;
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('Sin datos de atribución');
    expect(text).not.toMatch(/\b0\s*min\b/);
  });

  it('shows an empty state (not "0") when sample_count is 0 and data is fully attributed', () => {
    const emptyData: ReporteDeDemoraDeRespuesta = {
      datosDisponiblesDesde: '2026-01-01T00:00:00Z',
      datosParciales: false,
      global: { demoraPromedioSegundos: null, cantidadDeMuestras: 0, cantidadDeEntrantesSinResponder: 0 },
      porAgente: [],
    };
    component.data = emptyData;
    component.loading = false;
    fixture.detectChanges();

    const empty = fixture.debugElement.query(By.css('.kpi-card__empty'));
    expect(empty).toBeTruthy();
    expect((empty.nativeElement.textContent as string)).toContain('Sin muestras');
  });

  it('renders the aggregate average (in minutes) and per-agent table when samples exist', () => {
    const populated: ReporteDeDemoraDeRespuesta = {
      datosDisponiblesDesde: '2026-01-01T00:00:00Z',
      datosParciales: false,
      global: { demoraPromedioSegundos: 300, cantidadDeMuestras: 4, cantidadDeEntrantesSinResponder: 1 },
      porAgente: [
        { agenteId: 1, agenteNombre: 'Ana', demoraPromedioSegundos: 180, cantidadDeMuestras: 3 },
        { agenteId: 2, agenteNombre: null, demoraPromedioSegundos: null, cantidadDeMuestras: 0 },
      ],
    };
    component.data = populated;
    component.loading = false;
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('5'); // 300s = 5.0 min
    expect(text).toContain('4 muestras');
    expect(text).toContain('1 sin responder');
    expect(text).toContain('Ana');
    expect(text).toContain('Agente #2');

    const rows = fixture.debugElement.queryAll(By.css('.response-delay-table tbody tr'));
    expect(rows.length).toBe(2);
  });
});
