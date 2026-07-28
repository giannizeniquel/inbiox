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

    this.frtLoading.set(true);
    this.rtLoading.set(true);
    this.countsLoading.set(true);
    this.volumeLoading.set(true);
    this.agentsLoading.set(true);

    this.getReportsDashboardUseCase.execute({ params }).subscribe({
      next: (data) => {
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
      },
      error: () => {
        // On total failure, stop loading so UI shows empty state
        this.frtLoading.set(false);
        this.rtLoading.set(false);
        this.countsLoading.set(false);
        this.volumeLoading.set(false);
        this.agentsLoading.set(false);
      },
    });
  }
}
