import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DurationPipe } from '../../../../shared/pipes/duration.pipe';

@Component({
  selector: 'app-response-time-card',
  standalone: true,
  imports: [CommonModule, DurationPipe],
  templateUrl: './response-time-card.component.html',
  styleUrl: './response-time-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResponseTimeCardComponent {
  @Input({ required: true }) value: number = 0;
  @Input() loading: boolean = false;
}
