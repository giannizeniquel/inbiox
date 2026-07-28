import { Component, inject, input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { WorkflowApiService } from '../../../../core/api/workflow-api.service';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { Ticket, TicketStatus } from '../../../../core/models/ticket.model';

@Component({
  selector: 'app-status-actions',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './status-actions.component.html',
  styleUrl: './status-actions.component.scss',
})
export class StatusActionsComponent {
  readonly ticket = input.required<Ticket>();

  private readonly workflowApi = inject(WorkflowApiService);
  private readonly ticketStore = inject(TicketStore);

  // FSM allowed transitions
  private readonly allowedTransitions: Record<TicketStatus, TicketStatus[]> = {
    [TicketStatus.New]: [TicketStatus.Active, TicketStatus.Waiting],
    [TicketStatus.Active]: [TicketStatus.Waiting, TicketStatus.Resolved],
    [TicketStatus.Waiting]: [TicketStatus.Active],
    [TicketStatus.Resolved]: [TicketStatus.Closed],
    [TicketStatus.Closed]: [],
  };

  readonly actionLabels: Record<TicketStatus, string> = {
    [TicketStatus.New]: 'Iniciar',
    [TicketStatus.Active]: 'Activar',
    [TicketStatus.Waiting]: 'Esperar',
    [TicketStatus.Resolved]: 'Resolver',
    [TicketStatus.Closed]: 'Cerrar',
  };

  get availableActions(): TicketStatus[] {
    return this.allowedTransitions[this.ticket().status] ?? [];
  }

  executeAction(newStatus: TicketStatus): void {
    const ticket = this.ticket();
    if (!this.availableActions.includes(newStatus)) {
      console.warn('[StatusActions] Invalid transition attempted:', newStatus);
      return;
    }

    this.workflowApi.transition(ticket.ticket_id, newStatus).subscribe({
      next: (updatedTicket) => {
        this.ticketStore.addOrUpdateTicket(updatedTicket);
      },
      error: (err) => {
        console.error('[StatusActions] Transition failed:', err);
      },
    });
  }
}
