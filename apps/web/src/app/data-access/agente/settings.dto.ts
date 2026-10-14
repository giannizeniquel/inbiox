export interface SettingsProfile {
  name: string;
  email: string;
  signature: string | null;
}

export interface SettingsNotifications {
  sound: boolean;
  desktop: boolean;
}

export interface SettingsAutoReply {
  enabled: boolean;
  message: string | null;
}

export interface SettingsWorkspace {
  id: number;
  displayName: string;
  phoneNumberId: string;
}

export interface ShiftDTO {
  dayOfWeek: number;
  start: string;
  end: string;
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

export interface UpdateAutoReplyPayload {
  enabled?: boolean;
  message?: string | null;
}

export interface ShiftInput {
  dayOfWeek: number;
  start: string;
  end: string;
}
