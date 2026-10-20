import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DisponibilidadStore } from '../../../../data-access/disponibilidad/disponibilidad.store';

@Component({
  selector: 'app-disponibilidad-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './disponibilidad.page.html',
  styles: [`
    .availability-page {
      padding: 1rem;
      max-width: 600px;
    }
    h2 { margin-bottom: 1rem; }
    .current-state {
      background: var(--bg-surface, #f5f5f5);
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
    }
    .current-state table {
      width: 100%;
    }
    .current-state td {
      padding: 4px 8px;
    }
    .override-form {
      background: var(--nav-active-bg, #eef);
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
    }
    .override-form label {
      display: block;
      margin-bottom: 0.5rem;
    }
    .override-form select, .override-form input {
      margin-left: 0.5rem;
      padding: 4px 8px;
    }
    button {
      padding: 8px 16px;
      background: var(--accent-primary, #4a90d9);
      color: var(--text-on-accent, white);
      border: none;
      border-radius: 4px;
      cursor: pointer;
      margin-top: 0.5rem;
    }
    button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .btn-danger {
      background: var(--sla-breach, #d9534f);
    }
    .loading {
      color: var(--text-secondary, #666);
      font-style: italic;
    }
  `],
})
export class DisponibilidadPageComponent implements OnInit {
  readonly store = inject(DisponibilidadStore);

  ngOnInit(): void {
    const agent = this.store.currentAgent();
    if (agent !== null) {
      this.store.loadAvailability(agent.id);
    }
  }

  onSetOverride(isAvailable: boolean, ttlValue: string): void {
    const ttl = ttlValue ? parseInt(ttlValue, 10) : undefined;
    this.store.setAvailability(isAvailable, ttl);
  }

  onClearOverride(): void {
    this.store.clearAvailability();
  }
}
