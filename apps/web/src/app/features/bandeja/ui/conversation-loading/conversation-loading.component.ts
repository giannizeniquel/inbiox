import { Component, ChangeDetectionStrategy } from '@angular/core';

@Component({
  selector: 'app-conversation-loading',
  standalone: true,
  templateUrl: './conversation-loading.component.html',
  styleUrl: './conversation-loading.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversationLoadingComponent {}
