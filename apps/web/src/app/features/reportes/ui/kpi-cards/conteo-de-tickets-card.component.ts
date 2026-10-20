import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-conteo-de-tickets-card',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './conteo-de-tickets-card.component.html',
  styleUrl: './conteo-de-tickets-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConteoDeTicketsCardComponent {
  @Input({ required: true }) open: number = 0;
  @Input({ required: true }) closed: number = 0;
  @Input() loading: boolean = false;
}