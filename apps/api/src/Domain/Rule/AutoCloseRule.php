<?php

namespace App\Domain\Rule;

use App\Domain\Event\TicketEvent;
use App\Domain\Ticket\TicketStatus;
use App\Application\UseCase\TransitionTicketStatusUseCase;
use Doctrine\ORM\EntityManagerInterface;

class AutoCloseRule implements RuleInterface
{
    private int $thresholdHours;

    public function __construct(
        private TransitionTicketStatusUseCase $transitionUseCase,
        private EntityManagerInterface $em,
        int $thresholdHours = 24,
    ) {
        $this->thresholdHours = $thresholdHours;
    }

    public function supports(RuleContext $context): bool
    {
        // Only supports manual trigger on resolved tickets
        if ($context->getTriggerType() !== 'manual') {
            return false;
        }

        $ticket = $context->getTicket();
        if ($ticket->getStatus() !== TicketStatus::RESOLVED->value) {
            return false;
        }

        // Check if past threshold
        $lastMessageAt = $ticket->getUpdatedAt();
        if (!$lastMessageAt) {
            return false;
        }

        // Calculate hours since last update
        $now = new \DateTimeImmutable();
        $hoursSinceUpdate = ($now->getTimestamp() - $lastMessageAt->getTimestamp()) / 3600;

        return $hoursSinceUpdate >= $this->thresholdHours;
    }

    public function execute(RuleContext $context): void
    {
        $this->transitionUseCase->close($context->getTicketId());

        // Emit auto_closed event
        $event = new TicketEvent($context->getTicketId(), 'auto_closed', 'system');
        $this->em->persist($event);
        $this->em->flush();
    }

    public function getName(): string
    {
        return 'auto_close';
    }

    public function buildExecutionKey(RuleContext $context): string
    {
        return 'auto_close:' . $context->getTicketId();
    }
}