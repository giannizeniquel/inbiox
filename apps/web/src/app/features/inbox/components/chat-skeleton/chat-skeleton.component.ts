import { Component, ChangeDetectionStrategy } from '@angular/core';

@Component({
  selector: 'app-chat-skeleton',
  standalone: true,
  templateUrl: './chat-skeleton.component.html',
  styleUrl: './chat-skeleton.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ChatSkeletonComponent {
  readonly skeletons = Array(5).fill(null);
}