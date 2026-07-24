import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AgentStore } from '../../../core/stores/agent.store';

@Component({
  selector: 'app-availability-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="availability-page">
      <h2>Agent Availability</h2>

      @if (store.loading()) {
        <div class="loading">Loading...</div>
      }

      @if (store.availability(); as av) {
        <div class="current-state">
          <h3>Current State</h3>
          <table>
            <tr><td>In Shift</td><td>{{ av.isInShift ? 'Yes' : 'No' }}</td></tr>
            <tr><td>Available</td><td>{{ av.isAvailable ? 'Yes' : 'No' }}</td></tr>
            <tr><td>Current Load</td><td>{{ av.currentLoad }} / {{ av.maxCapacity }}</td></tr>
            <tr><td>Timezone</td><td>{{ av.timezone }}</td></tr>
            @if (av.override) {
              <tr>
                <td>Override Active</td>
                <td>
                  {{ av.override.active ? 'Yes' : 'No' }}
                  @if (av.override.expiresAt) {
                    <span>(expires {{ av.override.expiresAt | date:'short' }})</span>
                  }
                </td>
              </tr>
            }
          </table>
        </div>
      }

      <div class="override-form">
        <h3>Set Override</h3>
        <label>
          Available:
          <select #availableSelect>
            <option value="true">Available</option>
            <option value="false">Unavailable</option>
          </select>
        </label>

        <label>
          TTL (minutes, optional — leave empty for persistent):
          <input type="number" #ttlInput min="1" placeholder="e.g. 120" />
        </label>

        <button (click)="onSetOverride(availableSelect.value === 'true', ttlInput.value)">
          Apply Override
        </button>
      </div>

      <div class="actions">
        <button
          class="btn-danger"
          (click)="onClearOverride()"
          [disabled]="!store.availability()?.override?.active"
        >
          Clear Override
        </button>
      </div>
    </div>
  `,
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
export class AvailabilityPageComponent implements OnInit {
  readonly store = inject(AgentStore);

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
