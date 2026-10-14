<?php

namespace App\Application\UseCase;

use App\Domain\Tag\Tag;
use App\Repository\TagRepository;
use App\Domain\Tag\TicketTag;
use App\Domain\Event\TicketEvent;
use App\Domain\Ticket\Ticket;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AddTagToTicketUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private TagRepository $tagRepo,
        private TicketRepository $ticketRepo,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Add a tag to a ticket idempotently.
     */
    public function execute(int $ticketId, string $tagName, string $source = 'manual'): array
    {
        $normalizedName = Tag::normalize($tagName);

        return $this->em->getConnection()->transactional(function() use ($ticketId, $normalizedName, $source) {
            // Find ticket
            $ticket = $this->ticketRepo->findById($ticketId);
            if (!$ticket) {
                throw new \InvalidArgumentException('Ticket not found: ' . $ticketId);
            }

            // Find or create tag
            $tag = $this->tagRepo->findByName($normalizedName);
            if (!$tag) {
                $tag = new Tag($normalizedName);
                $this->em->persist($tag);
                $this->em->flush();
            }

            // Check if ticket already has this tag
            $existing = $this->em->getConnection()->executeQuery(
                "SELECT 1 FROM ticket_tags WHERE ticket_id = ? AND tag_id = ?",
                [$ticketId, $tag->getId()]
            )->fetchOne();

            // Idempotent - don't add duplicate or emit event
            if ($existing) {
                return [
                    'ticket_id' => $ticketId,
                    'tag_id' => $tag->getId(),
                    'tag_name' => $normalizedName,
                    'action' => 'already_exists',
                ];
            }

            // Add tag
            $ticketTag = new TicketTag($ticket, $tag);
            $this->em->persist($ticketTag);
            $this->em->flush();

            // Emit event
            $event = new TicketEvent($ticketId, 'tag_added', $source);
            $this->em->persist($event);
            $this->em->flush();

            return [
                'ticket_id' => $ticketId,
                'tag_id' => $tag->getId(),
                'tag_name' => $normalizedName,
                'action' => 'added',
            ];
        });
    }
}