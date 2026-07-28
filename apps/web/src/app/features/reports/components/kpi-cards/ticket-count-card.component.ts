import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-ticket-count-card',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './ticket-count-card.component.html',
  styleUrl: './ticket-count-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TicketCountCardComponent {
  @Input({ required: true }) open: number = 0;
  @Input({ required: true }) closed: number = 0;
  @Input() loading: boolean = false;
}