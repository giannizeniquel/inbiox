import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DurationPipe } from '../../../../shared/pipes/duration.pipe';

@Component({
  selector: 'app-resolution-time-card',
  standalone: true,
  imports: [CommonModule, DurationPipe],
  templateUrl: './resolution-time-card.component.html',
  styleUrl: './resolution-time-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResolutionTimeCardComponent {
  @Input({ required: true }) value: number = 0;
  @Input() loading: boolean = false;
}
