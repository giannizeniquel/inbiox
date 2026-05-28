import { Component, ChangeDetectionStrategy, Output, EventEmitter } from '@angular/core';

@Component({
  selector: 'app-conversation-error',
  standalone: true,
  templateUrl: './conversation-error.component.html',
  styleUrl: './conversation-error.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversationErrorComponent {
  @Output() retry = new EventEmitter<void>();
}