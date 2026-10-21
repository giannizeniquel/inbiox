import { Component, ChangeDetectionStrategy } from '@angular/core';

@Component({
  selector: 'app-conversacion-loading',
  standalone: true,
  templateUrl: './conversacion-loading.component.html',
  styleUrl: './conversacion-loading.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversacionLoadingComponent {}
