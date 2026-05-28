import { Component, inject, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { WorkflowApiService } from '../../../../core/api/workflow-api.service';
import { StateService } from '../../../../core/services/state.service';
import { Ticket, TicketStatus } from '../../../../core/models/ticket.model';
import { TicketApiService } from '../../../../core/api/ticket-api.service';

@Component({
  selector: 'app-status-actions',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="status-actions">
      @for (action of availableActions; track action) {
        <button
          class="action-btn"
          [attr.data-action]="action"
          (click)="executeAction(action)">
          {{ actionLabels[action] }}
        </button>
      }
    </div>
  `,
  styles: [`
    .status-actions {
      display: flex;
      gap: 0.5rem;
    }

    .action-btn {
      padding: 0.4rem 0.75rem;
      border: 1px solid #ddd;
      border-radius: 6px;
      background: #fff;
      font-size: 0.8rem;
      cursor: pointer;
      transition: all 0.15s ease;

      &:hover {
        background: #f5f5f5;
        border-color: #bbb;
      }

      &[data-action="active"] {
        background: #e8f5e9;
        border-color: #a5d6a7;
        color: #2e7d32;

        &:hover {
          background: #c8e6c9;
        }
      }

      &[data-action="waiting"] {
        background: #fff3e0;
        border-color: #ffcc80;
        color: #ef6c00;

        &:hover {
          background: #ffe0b2;
        }
      }

      &[data-action="resolved"] {
        background: #f3e5f5;
        border-color: #ce93d8;
        color: #7b1fa2;

        &:hover {
          background: #e1bee7;
        }
      }

      &[data-action="closed"] {
        background: #eceff1;
        border-color: #b0bec5;
        color: #546e7a;

        &:hover {
          background: #cfd8dc;
        }
      }
    }
  `]
})
export class StatusActionsComponent {
  @Input({ required: true }) ticket!: Ticket;

  private readonly workflowApi = inject(WorkflowApiService);
  private readonly ticketApi = inject(TicketApiService);
  private readonly state = inject(StateService);

  // FSM allowed transitions
  private readonly allowedTransitions: Record<TicketStatus, TicketStatus[]> = {
    [TicketStatus.New]: [TicketStatus.Active, TicketStatus.Waiting],
    [TicketStatus.Active]: [TicketStatus.Waiting, TicketStatus.Resolved],
    [TicketStatus.Waiting]: [TicketStatus.Active],
    [TicketStatus.Resolved]: [TicketStatus.Closed],
    [TicketStatus.Closed]: []
  };

  readonly actionLabels: Record<TicketStatus, string> = {
    [TicketStatus.New]: 'Iniciar',
    [TicketStatus.Active]: 'Activar',
    [TicketStatus.Waiting]: 'Esperar',
    [TicketStatus.Resolved]: 'Resolver',
    [TicketStatus.Closed]: 'Cerrar'
  };

  get availableActions(): TicketStatus[] {
    return this.allowedTransitions[this.ticket.status] ?? [];
  }

  executeAction(newStatus: TicketStatus): void {
    if (!this.availableActions.includes(newStatus)) {
      console.warn('[StatusActions] Invalid transition attempted:', newStatus);
      return;
    }

    this.workflowApi.transition(this.ticket.ticket_id, newStatus).subscribe({
      next: (updatedTicket) => {
        console.log('[StatusActions] Transitioned to:', newStatus);
        // Update local state
        this.state.addOrUpdateTicket(updatedTicket);
      },
      error: (err) => {
        console.error('[StatusActions] Transition failed:', err);
      }
    });
  }
}