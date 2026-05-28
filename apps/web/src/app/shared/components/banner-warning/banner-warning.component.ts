import { Component, ChangeDetectionStrategy } from '@angular/core';

@Component({
  selector: 'app-banner-warning',
  standalone: true,
  template: `
    <div class="banner-warning" role="alert" aria-live="polite">
      <svg class="banner-warning__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
        <line x1="12" y1="9" x2="12" y2="13"></line>
        <line x1="12" y1="17" x2="12.01" y2="17"></line>
      </svg>
      <ng-content></ng-content>
    </div>
  `,
  styleUrl: './banner-warning.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BannerWarningComponent {}
