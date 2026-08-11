import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TimeFilter } from '../../../../core/dto/reports.params';
import { BuildTimeFilterParamsUseCase } from '../../../../core/application/reports/build-time-filter-params.use-case';
import { GetReportsDashboardUseCase } from '../../../../core/application/reports/get-reports-dashboard.use-case';
import { ResponseTimeCardComponent } from '../../components/kpi-cards/response-time-card.component';
import { ResolutionTimeCardComponent } from '../../components/kpi-cards/resolution-time-card.component';
import { TicketCountCardComponent } from '../../components/kpi-cards/ticket-count-card.component';
import { VolumeTrendCardComponent, VolumeDataPoint } from '../../components/kpi-cards/volume-trend-card.component';
import { AgentLeaderboardComponent, AgentMetric } from '../../components/agent-leaderboard/agent-leaderboard.component';
import { AgentLoadCardComponent } from '../../components/agent-load-card/agent-load-card.component';
import { UnassignedCardComponent } from '../../components/unassigned-card/unassigned-card.component';
import { AvailableAgentsCardComponent } from '../../components/available-agents-card/available-agents-card.component';
import { FrtTrendCardComponent } from '../../components/frt-trend-card/frt-trend-card.component';
import { HeatmapCardComponent } from '../../components/heatmap-card/heatmap-card.component';
import { RecurringCardComponent } from '../../components/recurring-card/recurring-card.component';
import { ReopenedCardComponent } from '../../components/reopened-card/reopened-card.component';
import {
  AgentLoadEntry,
  UnassignedResponse,
  AvailableAgentEntry,
  FrtTrendPoint,
  HeatmapCell,
  RecurringEntry,
  ReopenedEntry,
} from '../../models/reports.models';

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
  ],
  templateUrl: './reports-page.component.html',
  styleUrl: './reports-page.component.scss',
})
export class ReportsPageComponent implements OnInit {
  private readonly buildTimeFilterParamsUseCase = inject(BuildTimeFilterParamsUseCase);
  private readonly getReportsDashboardUseCase = inject(GetReportsDashboardUseCase);

  readonly timeFilter = signal<TimeFilter>('7d');
  readonly fromDate = signal<string>('');
  readonly toDate = signal<string>('');

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

  private loadAllData(): void {
    const params = this.buildTimeFilterParamsUseCase.build(
      this.timeFilter(),
      this.fromDate(),
      this.toDate(),
    );

    // ── Set all loading states ──
    this.frtLoading.set(true);
    this.rtLoading.set(true);
    this.countsLoading.set(true);
    this.volumeLoading.set(true);
    this.agentsLoading.set(true);
    this.agentLoadLoading.set(true);
    this.unassignedLoading.set(true);
    this.frtTrendLoading.set(true);
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

        this.agentLoadLoading.set(false);
        this.unassignedLoading.set(false);
        this.frtTrendLoading.set(false);

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
        this.availableAgentsLoading.set(false);
        this.heatmapLoading.set(false);
        this.recurringLoading.set(false);
        this.reopenedLoading.set(false);
      },
    });
  }
}
