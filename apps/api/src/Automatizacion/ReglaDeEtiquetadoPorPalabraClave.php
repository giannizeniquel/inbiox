<?php

namespace App\Automatizacion;

use App\Ticket\UseCase\AgregarEtiquetaATicketUseCase;

class ReglaDeEtiquetadoPorPalabraClave implements ReglaInterface
{
    private const PALABRA_CLAVE = 'error';

    public function __construct(
        private AgregarEtiquetaATicketUseCase $agregarEtiquetaATicketUseCase,
    ) {}

    public function supports(ContextoDeRegla $contexto): bool
    {
        // Solo admite mensajes entrantes
        if ($contexto->getTipoDeDisparador() !== 'inbound') {
            return false;
        }

        $mensaje = $contexto->getMensaje();
        if (!$mensaje) {
            return false;
        }

        // Coincidencia de subcadena sin distinguir mayúsculas para "error"
        $contenido = $mensaje->getContenido();
        return stripos($contenido, self::PALABRA_CLAVE) !== false;
    }

    public function execute(ContextoDeRegla $contexto): void
    {
        $this->agregarEtiquetaATicketUseCase->execute(
            $contexto->getTicketId(),
            self::PALABRA_CLAVE,
            'system'
        );
    }

    public function getNombre(): string
    {
        return 'keyword_tagging';
    }

    public function armarClaveDeEjecucion(ContextoDeRegla $contexto): string
    {
        $ticketId = $contexto->getTicketId();
        $mensajeId = $contexto->getMensajeId() ?? 'no_message';
        return "tag_error:{$ticketId}:{$mensajeId}";
    }
}