import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-main-layout',
  standalone: true,
  imports: [CommonModule, RouterOutlet],
  template: `
    <div class="main-layout">
      <router-outlet />
    </div>
  `,
  styles: [`
    .main-layout {
      min-height: 100vh;
    }
  `]
})
export class MainLayoutComponent {}