<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use App\Conversacion\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * After Graph accepts an outbound message, stores its WhatsApp id (WAMID)
 * so status webhooks can find it, and marks it sent. A missing message is
 * ignored.
 */
final class MarkOutboundMessageSentUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageRepository $messageRepository,
    ) {}

    public function execute(int|string $messageId, string $wamid): void
    {
        $message = $this->messageRepository->find($messageId);
        if (!$message) {
            return;
        }

        $message->setExternalId($wamid);
        $message->setDeliveryStatus('sent');
        $this->entityManager->flush();
    }
}
