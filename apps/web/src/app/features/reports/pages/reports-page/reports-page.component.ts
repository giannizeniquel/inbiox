import { Component, inject, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReportsApiService } from '../../../../core/api/reports-api.service';
import { TimeFilter, TimeFilterParams } from '../../models/reports.models';
import { ResponseTimeCardComponent } from '../../components/kpi-cards/response-time-card.component';
import { ResolutionTimeCardComponent } from '../../components/kpi-cards/resolution-time-card.component';
import { TicketCountCardComponent } from '../../components/kpi-cards/ticket-count-card.component';
import { VolumeTrendCardComponent, VolumeDataPoint } from '../../components/kpi-cards/volume-trend-card.component';
import { AgentLeaderboardComponent } from '../../components/agent-leaderboard/agent-leaderboard.component';

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
  private readonly reportsApi = inject(ReportsApiService);

  // Signals for state
  readonly timeFilter = signal<TimeFilter>('7d');
  readonly fromDate = signal<string>('');
  readonly toDate = signal<string>('');

  // Loading states
  readonly frtLoading = signal(true);
  readonly rtLoading = signal(true);
  readonly countsLoading = signal(true);
  readonly volumeLoading = signal(true);
  readonly agentsLoading = signal(true);

  // Data signals
  readonly frtValue = signal(0);
  readonly rtValue = signal(0);
  readonly openCount = signal(0);
  readonly closedCount = signal(0);
  readonly volumeData = signal<VolumeDataPoint[]>([]);
  readonly agentsData = signal<any[]>([]);

  // Computed time filter params
  readonly timeParams = computed<TimeFilterParams>(() => {
    const filter = this.timeFilter();
    const today = new Date();

    switch (filter) {
      case 'today':
        return {
          fromDate: this.formatDate(today),
          toDate: this.formatDate(today),
        };
      case '7d':
        const sevenDaysAgo = new Date(today);
        sevenDaysAgo.setDate(today.getDate() - 7);
        return {
          fromDate: this.formatDate(sevenDaysAgo),
          toDate: this.formatDate(today),
        };
      case '30d':
        const thirtyDaysAgo = new Date(today);
        thirtyDaysAgo.setDate(today.getDate() - 30);
        return {
          fromDate: this.formatDate(thirtyDaysAgo),
          toDate: this.formatDate(today),
        };
      case 'custom':
        return {
          fromDate: this.fromDate(),
          toDate: this.toDate(),
        };
    }
  });

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
    const params = this.timeParams();

    // First Response Time
    this.frtLoading.set(true);
    this.reportsApi.getFirstResponseTime(params).subscribe({
      next: (data: any) => {
        this.frtValue.set(data.avg_frt_minutes || 0);
        this.frtLoading.set(false);
      },
      error: () => this.frtLoading.set(false),
    });

    // Resolution Time
    this.rtLoading.set(true);
    this.reportsApi.getResolutionTime(params).subscribe({
      next: (data: any) => {
        this.rtValue.set(data.avg_rt_minutes || 0);
        this.rtLoading.set(false);
      },
      error: () => this.rtLoading.set(false),
    });

    // Ticket Counts - backend returns: {new, active, waiting, resolved, closed}
    this.countsLoading.set(true);
    this.reportsApi.getTicketCounts(params).subscribe({
      next: (data: any) => {
        // open = new + active + waiting
        this.openCount.set((data.new || 0) + (data.active || 0) + (data.waiting || 0));
        this.closedCount.set((data.resolved || 0) + (data.closed || 0));
        this.countsLoading.set(false);
      },
      error: () => this.countsLoading.set(false),
    });

    // Volume Trend - backend returns array directly, map to {date, count}
    this.volumeLoading.set(true);
    this.reportsApi.getVolumeTrend(params).subscribe({
      next: (data) => {
        // Map tickets_created to count
        const mapped = ((data as any)?.data || data || []).map((item: any) => ({
          date: item.date,
          count: item.tickets_created || 0
        }));
        this.volumeData.set(mapped);
        this.volumeLoading.set(false);
      },
      error: () => this.volumeLoading.set(false),
    });

    // Agent Metrics - backend returns array with different field names
    this.agentsLoading.set(true);
    this.reportsApi.getAgentMetrics(params).subscribe({
      next: (data) => {
        // Map backend fields to what the component expects
        const agentList = (data as any)?.agents || data || [];
        const mapped = agentList.map((agent: any) => ({
          agentId: String(agent.agent_id),
          agentName: `Agent ${agent.agent_id}`,
          assigned: agent.tickets_assigned || 0,
          avgResponseMinutes: agent.avg_frt_minutes || 0,
          resolved: agent.tickets_resolved || 0,
          loadPercentage: Math.min(100, (agent.tickets_assigned || 0) * 20)
        }));
        this.agentsData.set(mapped);
        this.agentsLoading.set(false);
      },
      error: () => this.agentsLoading.set(false),
    });
  }

  private formatDate(date: Date): string {
    return date.toISOString().split('T')[0];
  }
}