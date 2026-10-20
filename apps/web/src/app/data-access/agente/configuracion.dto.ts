import { RespuestaAutomaticaDeConfiguracion } from '../automatizacion/respuesta-automatica-api.service';
import { TurnoDTO } from '../disponibilidad/disponibilidad-api.service';

export interface PerfilDeConfiguracion {
  name: string;
  email: string;
  signature: string | null;
}

export interface NotificacionesDeConfiguracion {
  sound: boolean;
  desktop: boolean;
}

export interface EspacioDeTrabajoDeConfiguracion {
  id: number;
  displayName: string;
  phoneNumberId: string;
}

export interface ConfiguracionResponse {
  profile: PerfilDeConfiguracion;
  notifications: NotificacionesDeConfiguracion;
  autoReply: RespuestaAutomaticaDeConfiguracion;
  workspace: EspacioDeTrabajoDeConfiguracion | null;
  businessHours: TurnoDTO[];
}

export interface UpdatePerfilPayload {
  signature: string | null;
}

export interface UpdateNotificacionesPayload {
  sound?: boolean;
  desktop?: boolean;
}
