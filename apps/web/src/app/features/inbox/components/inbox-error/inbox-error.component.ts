import { Component, Output, EventEmitter, ChangeDetectionStrategy } from '@angular/core';

@Component({
  selector: 'app-inbox-error',
  standalone: true,
  templateUrl: './inbox-error.component.html',
  styleUrl: './inbox-error.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InboxErrorComponent {
  @Output() retry = new EventEmitter<void>();
}