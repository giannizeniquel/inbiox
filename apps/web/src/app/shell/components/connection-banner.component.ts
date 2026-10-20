import { Component, ChangeDetectionStrategy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ConnectionStore } from '../../core/tiempo-real/connection.store';

@Component({
  selector: 'app-connection-banner',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './connection-banner.component.html',
  styleUrl: './connection-banner.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConnectionBannerComponent {
  private connectionStore = inject(ConnectionStore);

  status = this.connectionStore.status;
}