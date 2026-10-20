export interface EspacioDeTrabajoDTO {
  id: number;
  phone_number_id: string;
  display_name: string;
  is_active: boolean;
  agent_count?: number;
  agent_ids?: number[];
  has_pending?: boolean;
  created_at: string;
  updated_at: string;
}

export interface PreferenciaDeEspacioDeTrabajoResponse {
  workspace_id: number | null;
  phone_number_id: string | null;
  display_name: string | null;
}
