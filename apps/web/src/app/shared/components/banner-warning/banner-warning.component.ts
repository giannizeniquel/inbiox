import { Component, ChangeDetectionStrategy } from '@angular/core';

@Component({
  selector: 'app-banner-warning',
  standalone: true,
  templateUrl: './banner-warning.component.html',
  styleUrl: './banner-warning.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BannerWarningComponent {}
