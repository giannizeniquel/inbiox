import { Component, ChangeDetectionStrategy } from '@angular/core';

/**
 * ConversationSkeletonComponent — shimmer skeleton for loading state.
 *
 * Per spec section 17:
 * - 4 bubbles alternating left/right
 * - Shimmer animation
 */
@Component({
  selector: 'app-conversation-skeleton',
  standalone: true,
  templateUrl: './conversation-skeleton.component.html',
  styleUrl: './conversation-skeleton.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversationSkeletonComponent {
  readonly bubbles = [
    { align: 'right', width: 65 },
    { align: 'left', width: 55 },
    { align: 'right', width: 40 },
    { align: 'left', width: 70 },
    { align: 'right', width: 50 },
    { align: 'left', width: 45 },
    { align: 'right', width: 35 },
    { align: 'left', width: 60 },
  ];
}