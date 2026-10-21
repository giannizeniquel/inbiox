import { RespuestaAutomaticaDeConfiguracion } from '../automatizacion/respuesta-automatica-api.service';
import { TurnoDTO } from '../disponibilidad/disponibilidad-api.service';

export interface PerfilDeConfiguracion {
  nombre: string;
  email: string;
  firma: string | null;
}

export interface NotificacionesDeConfiguracion {
  sonido: boolean;
  escritorio: boolean;
}

export interface EspacioDeTrabajoDeConfiguracion {
  id: number;
  nombreVisible: string;
  phoneNumberId: string;
}

export interface ConfiguracionResponse {
  perfil: PerfilDeConfiguracion;
  notificaciones: NotificacionesDeConfiguracion;
  respuestaAutomatica: RespuestaAutomaticaDeConfiguracion;
  espacioDeTrabajo: EspacioDeTrabajoDeConfiguracion | null;
  horarioDeAtencion: TurnoDTO[];
}

export interface UpdatePerfilPayload {
  firma: string | null;
}

export interface UpdateNotificacionesPayload {
  sonido?: boolean;
  escritorio?: boolean;
}
