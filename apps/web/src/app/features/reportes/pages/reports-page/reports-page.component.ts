import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TimeFilter } from '../../../../data-access/reporte/reports.params';
import { AuthStore } from '../../../../core/auth/auth.store';
import { BuildTimeFilterParamsUseCase } from '../../../../data-access/reporte/build-time-filter-params.use-case';
import { GetReportsDashboardUseCase } from '../../../../data-access/reporte/get-reports-dashboard.use-case';
import { ExportTicketsCsvUseCase } from '../../../../data-access/reporte/export-tickets-csv.use-case';
import { ResponseTimeCardComponent } from '../../ui/kpi-cards/response-time-card.component';
import { ResolutionTimeCardComponent } from '../../ui/kpi-cards/resolution-time-card.component';
import { TicketCountCardComponent } from '../../ui/kpi-cards/ticket-count-card.component';
import { VolumeTrendCardComponent, VolumeDataPoint } from '../../ui/kpi-cards/volume-trend-card.component';
import { AgentLeaderboardComponent, AgentMetric } from '../../ui/agent-leaderboard/agent-leaderboard.component';
import { AgentLoadCardComponent } from '../../ui/agent-load-card/agent-load-card.component';
import { UnassignedCardComponent } from '../../ui/unassigned-card/unassigned-card.component';
import { AvailableAgentsCardComponent } from '../../ui/available-agents-card/available-agents-card.component';
import { FrtTrendCardComponent } from '../../ui/frt-trend-card/frt-trend-card.component';
import { HeatmapCardComponent } from '../../ui/heatmap-card/heatmap-card.component';
import { RecurringCardComponent } from '../../ui/recurring-card/recurring-card.component';
import { ReopenedCardComponent } from '../../ui/reopened-card/reopened-card.component';
import { ResponseDelayCardComponent } from '../../ui/response-delay-card/response-delay-card.component';
import { TeamFilterComponent } from '../../../bandeja/ui/team-filter/team-filter.component';
import {
  AgentLoadEntry,
  UnassignedResponse,
  AvailableAgentEntry,
  FrtTrendPoint,
  HeatmapCell,
  RecurringEntry,
  ReopenedEntry,
} from '../../../../data-access/reporte/reports.models';
import { ResponseDelayReport } from '../../../../data-access/reporte/response-delay.dto';

@Component({
  selector: 'app-reports-page',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    ResponseTimeCardComponent,
    ResolutionTimeCardComponent,
    TicketCountCardComponent,
    VolumeTrendCardComponent,
    AgentLeaderboardComponent,
    AgentLoadCardComponent,
    UnassignedCardComponent,
    AvailableAgentsCardComponent,
    FrtTrendCardComponent,
    HeatmapCardComponent,
    RecurringCardComponent,
    ReopenedCardComponent,
    ResponseDelayCardComponent,
    TeamFilterComponent,
  ],
  templateUrl: './reports-page.component.html',
  styleUrl: './reports-page.component.scss',
})
export class ReportsPageComponent implements OnInit {
  private readonly buildTimeFilterParamsUseCase = inject(BuildTimeFilterParamsUseCase);
  private readonly getReportsDashboardUseCase = inject(GetReportsDashboardUseCase);
  private readonly exportTicketsCsvUseCase = inject(ExportTicketsCsvUseCase);
  readonly authStore = inject(AuthStore);

  readonly timeFilter = signal<TimeFilter>('7d');
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
  readonly volumeData = signal<VolumeDataPoint[]>([]);
  readonly agentsData = signal<AgentMetric[]>([]);

  // ── Group B: Operational ──
  readonly agentLoadLoading = signal(true);
  readonly unassignedLoading = signal(true);
  readonly frtTrendLoading = signal(true);

  readonly agentLoadData = signal<AgentLoadEntry[]>([]);
  readonly unassignedData = signal<UnassignedResponse | null>(null);
  readonly frtTrendData = signal<FrtTrendPoint[]>([]);
  readonly responseDelayLoading = signal(true);
  readonly responseDelayData = signal<ResponseDelayReport | null>(null);

  // ── Group C: Detail ──
  readonly availableAgentsLoading = signal(true);
  readonly heatmapLoading = signal(true);
  readonly recurringLoading = signal(true);
  readonly reopenedLoading = signal(true);

  readonly availableAgentsData = signal<AvailableAgentEntry[]>([]);
  readonly heatmapData = signal<HeatmapCell[]>([]);
  readonly recurringData = signal<RecurringEntry[]>([]);
  readonly reopenedData = signal<ReopenedEntry[]>([]);

  ngOnInit(): void {
    this.loadAllData();
  }

  setTimeFilter(filter: TimeFilter): void {
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
