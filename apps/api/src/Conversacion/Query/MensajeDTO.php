<?php

namespace App\Conversacion\Query;

use App\Conversacion\ClavesDeAdjunto;

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
            'mensajeId' => $this->mensajeId,
            'direccion' => $this->direccion,
            'contenido' => $this->contenido,
            'creadoAt' => $this->creadoAt,
            'adjunto' => ClavesDeAdjunto::aContrato($this->adjunto),
            'agenteRemitenteId' => $this->agenteRemitenteId,
            'tipoDeRemitente' => $this->tipoDeRemitente,
        ];
    }
}
