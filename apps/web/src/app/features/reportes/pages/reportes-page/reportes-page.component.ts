import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { FiltroDeTiempo } from '../../../../data-access/reporte/reporte.params';
import { AuthStore } from '../../../../core/auth/auth.store';
import { ConstruirFiltroDeTiempoParamsUseCase } from '../../../../data-access/reporte/construir-filtro-de-tiempo-params.use-case';
import { GetReportsDashboardUseCase } from '../../../../data-access/reporte/get-reports-dashboard.use-case';
import { ExportarTicketsCsvUseCase } from '../../../../data-access/reporte/exportar-tickets-csv.use-case';
import { ResponseTimeCardComponent } from '../../ui/kpi-cards/response-time-card.component';
import { ResolutionTimeCardComponent } from '../../ui/kpi-cards/resolution-time-card.component';
import { ConteoDeTicketsCardComponent } from '../../ui/kpi-cards/conteo-de-tickets-card.component';
import { TendenciaDeVolumenCardComponent, VolumenDataPoint } from '../../ui/kpi-cards/tendencia-de-volumen-card.component';
import { RendimientoDeAgentesComponent, MetricaDeAgente } from '../../ui/rendimiento-de-agentes/rendimiento-de-agentes.component';
import { CargaDeAgenteCardComponent } from '../../ui/carga-de-agente-card/carga-de-agente-card.component';
import { SinAsignarCardComponent } from '../../ui/sin-asignar-card/sin-asignar-card.component';
import { AgentesDisponiblesCardComponent } from '../../ui/agentes-disponibles-card/agentes-disponibles-card.component';
import { TendenciaDeFrtCardComponent } from '../../ui/tendencia-de-frt-card/tendencia-de-frt-card.component';
import { MapaDeCalorCardComponent } from '../../ui/mapa-de-calor-card/mapa-de-calor-card.component';
import { ContactosRecurrentesCardComponent } from '../../ui/contactos-recurrentes-card/contactos-recurrentes-card.component';
import { ReabiertosCardComponent } from '../../ui/reabiertos-card/reabiertos-card.component';
import { DemoraDeRespuestaCardComponent } from '../../ui/demora-de-respuesta-card/demora-de-respuesta-card.component';
import { FiltroDeEquipoComponent } from '../../../bandeja/ui/filtro-de-equipo/filtro-de-equipo.component';
import {
  CargaDeAgenteEntry,
  SinAsignarResponse,
  AgenteDisponibleEntry,
  TendenciaDeFrtPoint,
  MapaDeCalorCell,
  ContactoRecurrenteEntry,
  ReabiertoEntry,
} from '../../../../data-access/reporte/reporte.models';
import { ReporteDeDemoraDeRespuesta } from '../../../../data-access/reporte/demora-de-respuesta.dto';

@Component({
  selector: 'app-reportes-page',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    ResponseTimeCardComponent,
    ResolutionTimeCardComponent,
    ConteoDeTicketsCardComponent,
    TendenciaDeVolumenCardComponent,
    RendimientoDeAgentesComponent,
    CargaDeAgenteCardComponent,
    SinAsignarCardComponent,
    AgentesDisponiblesCardComponent,
    TendenciaDeFrtCardComponent,
    MapaDeCalorCardComponent,
    ContactosRecurrentesCardComponent,
    ReabiertosCardComponent,
    DemoraDeRespuestaCardComponent,
    FiltroDeEquipoComponent,
  ],
  templateUrl: './reportes-page.component.html',
  styleUrl: './reportes-page.component.scss',
})
export class ReportesPageComponent implements OnInit {
  private readonly buildTimeFilterParamsUseCase = inject(ConstruirFiltroDeTiempoParamsUseCase);
  private readonly getReportsDashboardUseCase = inject(GetReportsDashboardUseCase);
  private readonly exportTicketsCsvUseCase = inject(ExportarTicketsCsvUseCase);
  readonly authStore = inject(AuthStore);

  readonly timeFilter = signal<FiltroDeTiempo>('7d');
  readonly fromDate = signal<string>('');
  readonly toDate = signal<string>('');
  /** Single-select team filter (team-area-entity, Slice 3) */
  readonly selectedTeamId = signal<number | null>(null);
  readonly exporting = signal(false);
  readonly exportError = signal<string | null>(null);

  // ── Group A: KPIs ──
  readonly frtLoading = signal(true);
  readonly rtLoading = signal(true);
  readonly countsLoading = signal(true);
  readonly volumeLoading = signal(true);
  readonly agentsLoading = signal(true);

  readonly frtValue = signal(0);
  readonly rtValue = signal(0);
  readonly openCount = signal(0);
  readonly closedCount = signal(0);
  readonly volumeData = signal<VolumenDataPoint[]>([]);
  readonly agentsData = signal<MetricaDeAgente[]>([]);

  // ── Group B: Operational ──
  readonly agentLoadLoading = signal(true);
  readonly unassignedLoading = signal(true);
  readonly frtTrendLoading = signal(true);

  readonly agentLoadData = signal<CargaDeAgenteEntry[]>([]);
  readonly unassignedData = signal<SinAsignarResponse | null>(null);
  readonly frtTrendData = signal<TendenciaDeFrtPoint[]>([]);
  readonly responseDelayLoading = signal(true);
  readonly responseDelayData = signal<ReporteDeDemoraDeRespuesta | null>(null);

  // ── Group C: Detail ──
  readonly availableAgentsLoading = signal(true);
  readonly heatmapLoading = signal(true);
  readonly recurringLoading = signal(true);
  readonly reopenedLoading = signal(true);

  readonly availableAgentsData = signal<AgenteDisponibleEntry[]>([]);
  readonly heatmapData = signal<MapaDeCalorCell[]>([]);
  readonly recurringData = signal<ContactoRecurrenteEntry[]>([]);
  readonly reopenedData = signal<ReabiertoEntry[]>([]);

  ngOnInit(): void {
    this.loadAllData();
  }

  setTimeFilter(filter: FiltroDeTiempo): void {
    this.timeFilter.set(filter);
    if (filter !== 'custom') {
      this.loadAllData();
    }
  }

  applyCustomFilter(): void {
    if (this.fromDate() && this.toDate()) {
      this.loadAllData();
    }
  }

  /** Handle team filter selection — reloads all dashboard data scoped to the team */
  onTeamFilterChange(teamId: number | null): void {
    this.selectedTeamId.set(teamId);
    this.loadAllData();
  }

  exportCsv(): void {
    const params = {
      ...this.buildTimeFilterParamsUseCase.build(
        this.timeFilter(),
        this.fromDate(),
        this.toDate(),
      ),
      ...(this.selectedTeamId() !== null ? { teamId: this.selectedTeamId()! } : {}),
    };

    this.exporting.set(true);
    this.exportError.set(null);

    this.exportTicketsCsvUseCase.execute(params).subscribe({
      next: () => {
        this.exporting.set(false);
      },
      error: () => {
        this.exporting.set(false);
        this.exportError.set('No se pudo exportar el CSV. Intentá nuevamente.');
      },
    });
  }

  private loadAllData(): void {
    const params = {
      ...this.buildTimeFilterParamsUseCase.build(
        this.timeFilter(),
        this.fromDate(),
        this.toDate(),
      ),
      ...(this.selectedTeamId() !== null ? { teamId: this.selectedTeamId()! } : {}),
    };

    // ── Set all loading states ──
    this.frtLoading.set(true);
    this.rtLoading.set(true);
    this.countsLoading.set(true);
    this.volumeLoading.set(true);
    this.agentsLoading.set(true);
    this.agentLoadLoading.set(true);
    this.unassignedLoading.set(true);
    this.frtTrendLoading.set(true);
    this.responseDelayLoading.set(true);
    this.availableAgentsLoading.set(true);
    this.heatmapLoading.set(true);
    this.recurringLoading.set(true);
    this.reopenedLoading.set(true);

    this.getReportsDashboardUseCase.execute({ params }).subscribe({
      next: (data) => {
        // ── Group A: KPIs ──
        this.frtValue.set(data.frt.value);
        this.rtValue.set(data.rt.value);
        this.openCount.set(data.counts.open);
        this.closedCount.set(data.counts.closed);
        this.volumeData.set(data.volume);
        this.agentsData.set(data.agents);

        this.frtLoading.set(false);
        this.rtLoading.set(false);
        this.countsLoading.set(false);
        this.volumeLoading.set(false);
        this.agentsLoading.set(false);

        // ── Group B: Operational ──
        this.agentLoadData.set(data.agentLoad);
        this.unassignedData.set(data.unassigned);
        this.frtTrendData.set(data.frtTrend);
        this.responseDelayData.set(data.responseDelay);

        this.agentLoadLoading.set(false);
        this.unassignedLoading.set(false);
        this.frtTrendLoading.set(false);
        this.responseDelayLoading.set(false);

        // ── Group C: Detail ──
        this.availableAgentsData.set(data.availableAgents);
        this.heatmapData.set(data.heatmap);
        this.recurringData.set(data.recurring);
        this.reopenedData.set(data.reopened);

        this.availableAgentsLoading.set(false);
        this.heatmapLoading.set(false);
        this.recurringLoading.set(false);
        this.reopenedLoading.set(false);
      },
      error: () => {
        // On total failure, stop all loading
        this.frtLoading.set(false);
        this.rtLoading.set(false);
        this.countsLoading.set(false);
        this.volumeLoading.set(false);
        this.agentsLoading.set(false);
        this.agentLoadLoading.set(false);
        this.unassignedLoading.set(false);
        this.frtTrendLoading.set(false);
        this.responseDelayLoading.set(false);
        this.availableAgentsLoading.set(false);
        this.heatmapLoading.set(false);
        this.recurringLoading.set(false);
        this.reopenedLoading.set(false);
      },
    });
  }
}
