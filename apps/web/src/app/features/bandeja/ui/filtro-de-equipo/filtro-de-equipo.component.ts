import { Component, ChangeDetectionStrategy, OnInit, inject, signal, output } from '@angular/core';
import { ListaDeEquiposApiService } from '../../../../data-access/agente/lista-de-equipos-api.service';
import { EquipoDTO } from '../../../../data-access/administracion/equipo.dto';

/**
 * FiltroDeEquipoComponent — single-select team filter dropdown (team-area-entity,
 * Slice 3). Fetches the requester's workspace-scoped teams from
 * `GET /api/teams` (built in Slice 1) and emits the selected team id (or
 * `null` for "all teams"). Independent of and composable with any other
 * ticket filter — this component owns no cross-filter state itself.
 */
@Component({
  selector: 'app-filtro-de-equipo',
  standalone: true,
  imports: [],
  templateUrl: './filtro-de-equipo.component.html',
  styleUrl: './filtro-de-equipo.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FiltroDeEquipoComponent implements OnInit {
  private readonly teamApi = inject(ListaDeEquiposApiService);

  readonly teams = signal<EquipoDTO[]>([]);
  readonly selectedTeamId = signal<number | null>(null);

  /** Emits the selected team id, or `null` when "all teams" is chosen */
  readonly teamChange = output<number | null>();

  ngOnInit(): void {
    this.teamApi.listTeams().subscribe({
      next: (teams) => this.teams.set(teams),
      error: (err) => {
        console.error('[TeamFilter] Failed to load teams:', err);
        this.teams.set([]);
      },
    });
  }

  onChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const teamId = value === '' ? null : Number(value);
    this.selectedTeamId.set(teamId);
    this.teamChange.emit(teamId);
  }
}
