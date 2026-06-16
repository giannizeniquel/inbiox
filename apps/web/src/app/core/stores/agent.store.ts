import { Injectable, signal } from '@angular/core';
import { AgentService, AgentAvailabilityResponse, AgentShiftResponse } from '../services/agent.service';

export interface AgentState {
  id: number;
  name: string;
  email: string;
  active: boolean;
  timezone: string;
  maxActiveTickets: number;
}

/**
 * AgentStore — manages agent availability and shift state via Angular signals.
 *
 * Mirrors the TicketStore pattern. Used by AvailabilityPage and ShiftsPage.
 */
@Injectable({ providedIn: 'root' })
export class AgentStore {
  /** All loaded agents (basic info) */
  readonly agents = signal<AgentState[]>([]);

  /** Currently selected agent for views */
  readonly currentAgent = signal<AgentState | null>(null);

  /** Current availability state for the selected agent */
  readonly availability = signal<AgentAvailabilityResponse | null>(null);

  /** Current shifts for the selected agent */
  readonly shifts = signal<AgentShiftResponse[]>([]);

  /** Loading indicator */
  readonly loading = signal<boolean>(false);

  constructor(private readonly agentService: AgentService) {}

  /**
   * Load agents list.
   */
  loadAgents(agents: AgentState[]): void {
    this.agents.set(agents);
  }

  /**
   * Set the current agent being viewed/managed.
   */
  setCurrentAgent(agent: AgentState | null): void {
    this.currentAgent.set(agent);
    if (agent !== null) {
      this.loadAvailability(agent.id);
      this.loadShifts(agent.id);
    } else {
      this.availability.set(null);
      this.shifts.set([]);
    }
  }

  /**
   * Load availability for a specific agent.
   */
  loadAvailability(agentId: number): void {
    this.loading.set(true);
    this.agentService.getAvailability(agentId).subscribe({
      next: (result) => {
        this.availability.set(result);
        this.loading.set(false);
      },
      error: () => {
        this.availability.set(null);
        this.loading.set(false);
      },
    });
  }

  /**
   * Set availability override.
   */
  setAvailability(isAvailable: boolean, ttlMinutes?: number): void {
    const agent = this.currentAgent();
    if (agent === null) return;

    this.loading.set(true);
    this.agentService.setAvailability(agent.id, isAvailable, ttlMinutes).subscribe({
      next: () => {
        this.loadAvailability(agent.id);
      },
      error: () => {
        this.loading.set(false);
      },
    });
  }

  /**
   * Clear availability override.
   */
  clearAvailability(): void {
    const agent = this.currentAgent();
    if (agent === null) return;

    this.loading.set(true);
    this.agentService.clearAvailability(agent.id).subscribe({
      next: () => {
        this.loadAvailability(agent.id);
      },
      error: () => {
        this.loading.set(false);
      },
    });
  }

  /**
   * Load shifts for a specific agent.
   */
  loadShifts(agentId: number): void {
    this.loading.set(true);
    // Note: Shifts are loaded as part of availability endpoint or via a dedicated endpoint.
    // For now we reload availability which includes shift info,
    // and manage shifts through the service directly.
    this.loading.set(false);
  }

  /**
   * Create a new shift.
   */
  createShift(dayOfWeek: number, startTime: string, endTime: string): void {
    const agent = this.currentAgent();
    if (agent === null) return;

    this.loading.set(true);
    this.agentService.createShift(agent.id, dayOfWeek, startTime, endTime).subscribe({
      next: (shift) => {
        this.shifts.update((current) => [...current, shift]);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
      },
    });
  }

  /**
   * Delete a shift.
   */
  deleteShift(shiftId: number): void {
    const agent = this.currentAgent();
    if (agent === null) return;

    this.loading.set(true);
    this.agentService.deleteShift(agent.id, shiftId).subscribe({
      next: () => {
        this.shifts.update((current) =>
          current.filter((s) => s.id !== shiftId)
        );
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
      },
    });
  }

  /**
   * Reset store state.
   */
  reset(): void {
    this.agents.set([]);
    this.currentAgent.set(null);
    this.availability.set(null);
    this.shifts.set([]);
    this.loading.set(false);
  }
}
