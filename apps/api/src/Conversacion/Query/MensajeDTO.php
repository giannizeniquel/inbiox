<?php

namespace App\Conversacion\Query;

class MensajeDTO
{
    public function __construct(
        public int $mensajeId,
        public string $direccion,
        public string $contenido,
        public string $creadoAt,
        public ?array $adjunto = null,
        public ?int $agenteRemitenteId = null,
        public ?string $tipoDeRemitente = null,
    ) {}

    public function toArray(): array
    {
        return [
            'message_id' => $this->mensajeId,
            'direction' => $this->direccion,
            'content' => $this->contenido,
            'created_at' => $this->creadoAt,
            'media' => $this->adjunto,
            'sender_agent_id' => $this->agenteRemitenteId,
            'sender_type' => $this->tipoDeRemitente,
        ];
    }
}
