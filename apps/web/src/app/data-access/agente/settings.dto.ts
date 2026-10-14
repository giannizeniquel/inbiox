import { SettingsAutoReply } from '../automatizacion/auto-reply-api.service';
import { ShiftDTO } from '../disponibilidad/agent.service';

export interface SettingsProfile {
  name: string;
  email: string;
  signature: string | null;
}

export interface SettingsNotifications {
  sound: boolean;
  desktop: boolean;
}

export interface SettingsWorkspace {
  id: number;
  displayName: string;
  phoneNumberId: string;
}

export interface SettingsResponse {
  profile: SettingsProfile;
  notifications: SettingsNotifications;
  autoReply: SettingsAutoReply;
  workspace: SettingsWorkspace | null;
  businessHours: ShiftDTO[];
}

export interface UpdateProfilePayload {
  signature: string | null;
}

export interface UpdateNotificationsPayload {
  sound?: boolean;
  desktop?: boolean;
}
