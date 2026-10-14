import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { ResponseDelayCardComponent } from './response-delay-card.component';
import { ResponseDelayReport } from '../../../../data-access/reporte/reports.models';

/**
 * ResponseDelayCardComponent tests — documents the never-zero / partial-data
 * rendering contract from spec.md "Reports Page Delay Surface".
 *
 * NOTE: Karma/Jasmine cannot run in this WSL2 environment (no Chrome).
 * Written for documentation / future-run value; `ng build --configuration
 * production` is the enforceable frontend gate for this change.
 */
describe('ResponseDelayCardComponent', () => {
  let fixture: ComponentFixture<ResponseDelayCardComponent>;
  let component: ResponseDelayCardComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ResponseDelayCardComponent],
    }).compileComponents();

    fixture = TestBed.createComponent(ResponseDelayCardComponent);
    component = fixture.componentInstance;
  });

  it('shows the "sin datos de atribución" notice on partial_data, never a zero value', () => {
    const partialData: ResponseDelayReport = {
      data_available_from: '2026-06-01T00:00:00Z',
      partial_data: true,
      aggregate: { avg_delay_seconds: 0, sample_count: 0, unanswered_inbound_count: 0 },
      per_agent: [],
    };
    component.data = partialData;
    component.loading = false;
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('Sin datos de atribución');
    expect(text).not.toMatch(/\b0\s*min\b/);
  });

  it('shows an empty state (not "0") when sample_count is 0 and data is fully attributed', () => {
    const emptyData: ResponseDelayReport = {
      data_available_from: '2026-01-01T00:00:00Z',
      partial_data: false,
      aggregate: { avg_delay_seconds: null, sample_count: 0, unanswered_inbound_count: 0 },
      per_agent: [],
    };
    component.data = emptyData;
    component.loading = false;
    fixture.detectChanges();

    const empty = fixture.debugElement.query(By.css('.kpi-card__empty'));
    expect(empty).toBeTruthy();
    expect((empty.nativeElement.textContent as string)).toContain('Sin muestras');
  });

  it('renders the aggregate average (in minutes) and per-agent table when samples exist', () => {
    const populated: ResponseDelayReport = {
      data_available_from: '2026-01-01T00:00:00Z',
      partial_data: false,
      aggregate: { avg_delay_seconds: 300, sample_count: 4, unanswered_inbound_count: 1 },
      per_agent: [
        { agent_id: 1, agent_name: 'Ana', avg_delay_seconds: 180, sample_count: 3 },
        { agent_id: 2, agent_name: null, avg_delay_seconds: null, sample_count: 0 },
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
