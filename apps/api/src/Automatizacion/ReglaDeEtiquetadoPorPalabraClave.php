<?php

namespace App\Automatizacion;

use App\Ticket\UseCase\AgregarEtiquetaATicketUseCase;

class ReglaDeEtiquetadoPorPalabraClave implements ReglaInterface
{
    private const PALABRA_CLAVE = 'error';

    public function __construct(
        private AgregarEtiquetaATicketUseCase $addTagUseCase,
    ) {}

    public function supports(ContextoDeRegla $context): bool
    {
        // Only supports inbound messages
        if ($context->getTipoDeDisparador() !== 'inbound') {
            return false;
        }

        $message = $context->getMensaje();
        if (!$message) {
            return false;
        }

        // Case-insensitive substring match for "error"
        $content = $message->getContent();
        return stripos($content, self::PALABRA_CLAVE) !== false;
    }

    public function execute(ContextoDeRegla $context): void
    {
        $this->addTagUseCase->execute(
            $context->getTicketId(),
            self::PALABRA_CLAVE,
            'system'
        );
    }

    public function getNombre(): string
    {
        return 'keyword_tagging';
    }

    public function armarClaveDeEjecucion(ContextoDeRegla $context): string
    {
        $ticketId = $context->getTicketId();
        $messageId = $context->getMensajeId() ?? 'no_message';
        return "tag_error:{$ticketId}:{$messageId}";
    }
}