import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AgentStore } from '../../../core/stores/agent.store';

/**
 * AvailabilityPage — manage an agent's availability override.
 *
 * UI controls:
 * - Toggle available / unavailable
 * - Optional TTL minutes input (null = persistent)
 * - Show current override state (active, expires_at)
 * - Clear override button
 *
 * Wire to AgentStore. Minimal UI — Sprint 10.5 adds the capacity bar.
 */
@Component({
  selector: 'app-availability-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="availability-page">
      <h2>Agent Availability</h2>

      <div *ngIf="store.loading()" class="loading">Loading...</div>

      <div *ngIf="store.availability() as av" class="current-state">
        <h3>Current State</h3>
        <table>
          <tr><td>In Shift</td><td>{{ av.isInShift ? 'Yes' : 'No' }}</td></tr>
          <tr><td>Available</td><td>{{ av.isAvailable ? 'Yes' : 'No' }}</td></tr>
          <tr><td>Current Load</td><td>{{ av.currentLoad }} / {{ av.maxCapacity }}</td></tr>
          <tr><td>Timezone</td><td>{{ av.timezone }}</td></tr>
          <tr *ngIf="av.override">
            <td>Override Active</td>
            <td>
              {{ av.override.active ? 'Yes' : 'No' }}
              <span *ngIf="av.override.expiresAt">
                (expires {{ av.override.expiresAt | date:'short' }})
              </span>
            </td>
          </tr>
        </table>
      </div>

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
      background: #f5f5f5;
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
      background: #eef;
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
      background: #4a90d9;
      color: white;
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
      background: #d9534f;
    }
    .loading {
      color: #666;
      font-style: italic;
    }
  `],
})
export class AvailabilityPageComponent implements OnInit {
  readonly store = inject(AgentStore);

  ngOnInit(): void {
    // Load availability for the current agent (set externally via store.setCurrentAgent)
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
