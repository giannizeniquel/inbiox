import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AgentStore } from '../../../core/stores/agent.store';

const DAY_NAMES: Record<number, string> = {
  1: 'Monday',
  2: 'Tuesday',
  3: 'Wednesday',
  4: 'Thursday',
  5: 'Friday',
  6: 'Saturday',
  7: 'Sunday',
};

@Component({
  selector: 'app-shifts-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="shifts-page">
      <h2>Agent Shifts</h2>

      @if (store.loading()) {
        <div class="loading">Loading...</div>
      }

      @if (store.shifts().length > 0) {
        <div class="shift-list">
          <h3>Current Shifts</h3>
          <table>
            <thead>
              <tr>
                <th>Day</th>
                <th>Start</th>
                <th>End</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @for (shift of store.shifts(); track shift.id) {
                <tr>
                  <td>{{ getDayName(shift.dayOfWeek) }}</td>
                  <td>{{ shift.startTime }}</td>
                  <td>{{ shift.endTime }}</td>
                  <td>
                    <button class="btn-danger btn-small" (click)="onDeleteShift(shift.id)">
                      Delete
                    </button>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }

      @if (store.shifts().length === 0 && !store.loading()) {
        <div class="empty">
          No shifts defined. Create one below.
        </div>
      }

      <div class="shift-form">
        <h3>Create Shift</h3>
        <label>
          Day of Week:
          <select #daySelect>
            <option value="1">Monday</option>
            <option value="2">Tuesday</option>
            <option value="3">Wednesday</option>
            <option value="4">Thursday</option>
            <option value="5">Friday</option>
            <option value="6">Saturday</option>
            <option value="7">Sunday</option>
          </select>
        </label>

        <label>
          Start Time:
          <input type="time" #startTimeInput />
        </label>

        <label>
          End Time:
          <input type="time" #endTimeInput />
        </label>

        <button (click)="onCreateShift(daySelect.value, startTimeInput.value, endTimeInput.value)">
          Create Shift
        </button>
      </div>
    </div>
  `,
  styles: [`
    .shifts-page {
      padding: 1rem;
      max-width: 600px;
    }
    h2 { margin-bottom: 1rem; }
    h3 { margin-bottom: 0.5rem; }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 1rem;
    }
    th, td {
      padding: 8px;
      text-align: left;
      border-bottom: 1px solid var(--border-subtle, #ddd);
    }
    th {
      background: var(--bg-surface, #f5f5f5);
    }
    .shift-form {
      background: var(--nav-active-bg, #eef);
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
    }
    .shift-form label {
      display: block;
      margin-bottom: 0.5rem;
    }
    .shift-form select, .shift-form input {
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
    .btn-danger {
      background: var(--sla-breach, #d9534f);
    }
    .btn-small {
      padding: 4px 8px;
      font-size: 0.85rem;
    }
    .loading {
      color: var(--text-secondary, #666);
      font-style: italic;
    }
    .empty {
      color: var(--text-muted, #999);
      font-style: italic;
      margin-bottom: 1rem;
    }
  `],
})
export class ShiftsPageComponent implements OnInit {
  readonly store = inject(AgentStore);

  ngOnInit(): void {
    const agent = this.store.currentAgent();
    if (agent !== null) {
      this.store.loadShifts(agent.id);
    }
  }

  getDayName(dayOfWeek: number): string {
    return DAY_NAMES[dayOfWeek] || `Day ${dayOfWeek}`;
  }

  onCreateShift(dayOfWeek: string, startTime: string, endTime: string): void {
    if (!startTime || !endTime) {
      return;
    }
    this.store.createShift(parseInt(dayOfWeek, 10), startTime, endTime);
  }

  onDeleteShift(shiftId: number): void {
    this.store.deleteShift(shiftId);
  }
}
