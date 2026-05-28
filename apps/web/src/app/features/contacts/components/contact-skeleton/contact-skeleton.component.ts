import { Component, ChangeDetectionStrategy } from '@angular/core';

/**
 * ContactSkeletonComponent — loading skeleton for the contact list.
 *
 * Per spec:
 * - Displays 5 skeleton rows matching ContactCard dimensions
 * - CSS skeleton shimmer (dark/light mode compatible via CSS variables)
 * - No heavy animations
 * - Standalone static display (no inputs/outputs)
 */
@Component({
  selector: 'app-contact-skeleton',
  standalone: true,
  imports: [],
  templateUrl: './contact-skeleton.component.html',
  styleUrl: './contact-skeleton.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactSkeletonComponent {
  /** Number of skeleton rows to display */
  readonly rows = [1, 2, 3, 4, 5];
}