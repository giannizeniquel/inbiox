<?php

namespace App\Automatizacion;

use App\Ticket\UseCase\AgregarEtiquetaATicketUseCase;

class ReglaDeEtiquetadoPorPalabraClave implements ReglaInterface
{
    private const KEYWORD = 'error';

    public function __construct(
        private AgregarEtiquetaATicketUseCase $addTagUseCase,
    ) {}

    public function supports(ContextoDeRegla $context): bool
    {
        // Only supports inbound messages
        if ($context->getTriggerType() !== 'inbound') {
            return false;
        }

        $message = $context->getMessage();
        if (!$message) {
            return false;
        }

        // Case-insensitive substring match for "error"
        $content = $message->getContent();
        return stripos($content, self::KEYWORD) !== false;
    }

    public function execute(ContextoDeRegla $context): void
    {
        $this->addTagUseCase->execute(
            $context->getTicketId(),
            self::KEYWORD,
            'system'
        );
    }

    public function getName(): string
    {
        return 'keyword_tagging';
    }

    public function buildExecutionKey(ContextoDeRegla $context): string
    {
        $ticketId = $context->getTicketId();
        $messageId = $context->getMessageId() ?? 'no_message';
        return "tag_error:{$ticketId}:{$messageId}";
    }
}