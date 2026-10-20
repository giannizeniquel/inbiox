import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { FiltroDeTiempo } from '../../../../data-access/reporte/reporte.params';
import { AuthStore } from '../../../../core/auth/auth.store';
import { ArmarFiltroDeTiempoParamsUseCase } from '../../../../data-access/reporte/armar-filtro-de-tiempo-params.use-case';
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
  private readonly armarFiltroDeTiempoParamsUseCase = inject(ArmarFiltroDeTiempoParamsUseCase);
  private readonly getReportsDashboardUseCase = inject(GetReportsDashboardUseCase);
  private readonly exportarTicketsCsvUseCase = inject(ExportarTicketsCsvUseCase);
  readonly authStore = inject(AuthStore);

  readonly filtroDeTiempo = signal<FiltroDeTiempo>('7d');
  readonly fromDate = signal<string>('');
  readonly toDate = signal<string>('');
  /** Single-select team filter (team-area-entity, Slice 3) */
  readonly equipoSeleccionadoId = signal<number | null>(null);
  readonly exporting = signal(false);
  readonly errorDeExportacion = signal<string | null>(null);

  // ── Group A: KPIs ──
  readonly frtLoading = signal(true);
  readonly rtLoading = signal(true);
  readonly conteosLoading = signal(true);
  readonly volumenLoading = signal(true);
  readonly agentesLoading = signal(true);

  readonly frtValue = signal(0);
  readonly rtValue = signal(0);
  readonly openCount = signal(0);
  readonly closedCount = signal(0);
  readonly volumenData = signal<VolumenDataPoint[]>([]);
  readonly agentesData = signal<MetricaDeAgente[]>([]);

  // ── Group B: Operational ──
  readonly cargaDeAgenteLoading = signal(true);
  readonly sinAsignarLoading = signal(true);
  readonly tendenciaDeFrtLoading = signal(true);

  readonly cargaDeAgenteData = signal<CargaDeAgenteEntry[]>([]);
  readonly sinAsignarData = signal<SinAsignarResponse | null>(null);
  readonly tendenciaDeFrtData = signal<TendenciaDeFrtPoint[]>([]);
  readonly demoraDeRespuestaLoading = signal(true);
  readonly demoraDeRespuestaData = signal<ReporteDeDemoraDeRespuesta | null>(null);

  // ── Group C: Detail ──
  readonly agentesDisponiblesLoading = signal(true);
  readonly mapaDeCalorLoading = signal(true);
  readonly contactosRecurrentesLoading = signal(true);
  readonly reabiertosLoading = signal(true);

  readonly agentesDisponiblesData = signal<AgenteDisponibleEntry[]>([]);
  readonly mapaDeCalorData = signal<MapaDeCalorCell[]>([]);
  readonly contactosRecurrentesData = signal<ContactoRecurrenteEntry[]>([]);
  readonly reabiertosData = signal<ReabiertoEntry[]>([]);

  ngOnInit(): void {
    this.cargarTodo();
  }

  setFiltroDeTiempo(filtro: FiltroDeTiempo): void {
    this.filtroDeTiempo.set(filtro);
    if (filtro !== 'custom') {
      this.cargarTodo();
    }
  }

  aplicarFiltroPersonalizado(): void {
    if (this.fromDate() && this.toDate()) {
      this.cargarTodo();
    }
  }

  /** Handle team filter selection — reloads all dashboard data scoped to the team */
  onFiltroDeEquipoChange(equipoId: number | null): void {
    this.equipoSeleccionadoId.set(equipoId);
    this.cargarTodo();
  }

  exportarCsv(): void {
    const params = {
      ...this.armarFiltroDeTiempoParamsUseCase.build(
        this.filtroDeTiempo(),
        this.fromDate(),
        this.toDate(),
      ),
      ...(this.equipoSeleccionadoId() !== null ? { teamId: this.equipoSeleccionadoId()! } : {}),
    };

    this.exporting.set(true);
    this.errorDeExportacion.set(null);

    this.exportarTicketsCsvUseCase.execute(params).subscribe({
      next: () => {
        this.exporting.set(false);
      },
      error: () => {
        this.exporting.set(false);
        this.errorDeExportacion.set('No se pudo exportar el CSV. Intentá nuevamente.');
      },
    });
  }

  private cargarTodo(): void {
    const params = {
      ...this.armarFiltroDeTiempoParamsUseCase.build(
        this.filtroDeTiempo(),
        this.fromDate(),
        this.toDate(),
      ),
      ...(this.equipoSeleccionadoId() !== null ? { teamId: this.equipoSeleccionadoId()! } : {}),
    };

    // ── Set all loading states ──
    this.frtLoading.set(true);
    this.rtLoading.set(true);
    this.conteosLoading.set(true);
    this.volumenLoading.set(true);
    this.agentesLoading.set(true);
    this.cargaDeAgenteLoading.set(true);
    this.sinAsignarLoading.set(true);
    this.tendenciaDeFrtLoading.set(true);
    this.demoraDeRespuestaLoading.set(true);
    this.agentesDisponiblesLoading.set(true);
    this.mapaDeCalorLoading.set(true);
    this.contactosRecurrentesLoading.set(true);
    this.reabiertosLoading.set(true);

    this.getReportsDashboardUseCase.execute({ params }).subscribe({
      next: (data) => {
        // ── Group A: KPIs ──
        this.frtValue.set(data.frt.value);
        this.rtValue.set(data.rt.value);
        this.openCount.set(data.counts.open);
        this.closedCount.set(data.counts.closed);
        this.volumenData.set(data.volume);
        this.agentesData.set(data.agents);

        this.frtLoading.set(false);
        this.rtLoading.set(false);
        this.conteosLoading.set(false);
        this.volumenLoading.set(false);
        this.agentesLoading.set(false);

        // ── Group B: Operational ──
        this.cargaDeAgenteData.set(data.agentLoad);
        this.sinAsignarData.set(data.unassigned);
        this.tendenciaDeFrtData.set(data.frtTrend);
        this.demoraDeRespuestaData.set(data.responseDelay);

        this.cargaDeAgenteLoading.set(false);
        this.sinAsignarLoading.set(false);
        this.tendenciaDeFrtLoading.set(false);
        this.demoraDeRespuestaLoading.set(false);

        // ── Group C: Detail ──
        this.agentesDisponiblesData.set(data.availableAgents);
        this.mapaDeCalorData.set(data.heatmap);
        this.contactosRecurrentesData.set(data.recurring);
        this.reabiertosData.set(data.reopened);

        this.agentesDisponiblesLoading.set(false);
        this.mapaDeCalorLoading.set(false);
        this.contactosRecurrentesLoading.set(false);
        this.reabiertosLoading.set(false);
      },
      error: () => {
        // On total failure, stop all loading
        this.frtLoading.set(false);
        this.rtLoading.set(false);
        this.conteosLoading.set(false);
        this.volumenLoading.set(false);
        this.agentesLoading.set(false);
        this.cargaDeAgenteLoading.set(false);
        this.sinAsignarLoading.set(false);
        this.tendenciaDeFrtLoading.set(false);
        this.demoraDeRespuestaLoading.set(false);
        this.agentesDisponiblesLoading.set(false);
        this.mapaDeCalorLoading.set(false);
        this.contactosRecurrentesLoading.set(false);
        this.reabiertosLoading.set(false);
      },
    });
  }
}
