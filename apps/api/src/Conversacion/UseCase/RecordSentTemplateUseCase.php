<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Conversacion\Message;
use App\Conversacion\MessageDirection;
use App\Conversacion\MessageSenderType;
use App\Ticket\Ticket;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists a template that Graph accepted as a new outbound message, with
 * its WAMID as externalId for webhook correlation.
 */
final class RecordSentTemplateUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $parameters the template parameters sent to Graph
     */
    public function execute(Ticket $ticket, string $templateName, array $parameters, string $wamid, ?int $senderAgentId): void
    {
        $content = "Template: {$templateName}" . (!empty($parameters) ? ' (' . implode(', ', $parameters) . ')' : '');
        $message = new Message();
        $message->setContent($content);
        $message->setExternalId($wamid);
        $message->setDirection(MessageDirection::OUTBOUND->value);
        $message->setDeliveryStatus('sent');
        $message->setUpdatedAt(new \DateTimeImmutable());
        $message->setSenderAgentId($senderAgentId);
        $message->setSenderType(MessageSenderType::AGENT);
        if ($ticket->getConversation()) {
            $message->setConversation($ticket->getConversation());
        }
        $this->entityManager->persist($message);
        $this->entityManager->flush();
    }
}
