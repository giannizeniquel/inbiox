import { Component, ChangeDetectionStrategy, OnInit, inject, signal, output } from '@angular/core';
import { ListaDeEquiposApiService } from '../../../../data-access/agente/lista-de-equipos-api.service';
import { EquipoDTO } from '../../../../data-access/administracion/equipo.dto';

/**
 * FiltroDeEquipoComponent — single-select team filter dropdown (team-area-entity,
 * Slice 3). Fetches the requester's workspace-scoped teams from
 * `GET /api/equipos` (built in Slice 1) and emits the selected team id (or
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
  private readonly equipoApi = inject(ListaDeEquiposApiService);

  readonly equipos = signal<EquipoDTO[]>([]);
  readonly equipoSeleccionadoId = signal<number | null>(null);

  /** Emits the selected team id, or `null` when "all teams" is chosen */
  readonly equipoChange = output<number | null>();

  ngOnInit(): void {
    this.equipoApi.listarEquipos().subscribe({
      next: (equipos) => this.equipos.set(equipos),
      error: (err) => {
        console.error('[TeamFilter] Failed to load teams:', err);
        this.equipos.set([]);
      },
    });
  }

  onChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const equipoId = value === '' ? null : Number(value);
    this.equipoSeleccionadoId.set(equipoId);
    this.equipoChange.emit(equipoId);
  }
}
