export interface EspacioDeTrabajoDTO {
  id: number;
  phoneNumberId: string;
  nombreVisible: string;
  activo: boolean;
  cantidadDeAgentes?: number;
  agenteIds?: number[];
  hayPendientes?: boolean;
  creadoAt: string;
  actualizadoAt: string;
}

export interface PreferenciaDeEspacioDeTrabajoResponse {
  espacioDeTrabajoId: number | null;
  phoneNumberId: string | null;
  nombreVisible: string | null;
}
