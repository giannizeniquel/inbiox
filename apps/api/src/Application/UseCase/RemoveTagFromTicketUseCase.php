<?php

namespace App\Application\UseCase;

use App\Domain\Tag\Tag;
use App\Domain\Tag\TagRepositoryInterface;
use App\Domain\Event\TicketEvent;
use App\Domain\Ticket\TicketRepositoryInterface;
use App\Repository\TagRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class RemoveTagFromTicketUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private TagRepositoryInterface $tagRepo,
        private TicketRepositoryInterface $ticketRepo,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Remove a tag from a ticket.
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

            // Find tag
            $tag = $this->tagRepo->findByName($normalizedName);
            if (!$tag) {
                // Tag doesn't exist - nothing to remove, but return success
                return [
                    'ticket_id' => $ticketId,
                    'tag_name' => $normalizedName,
                    'action' => 'not_found',
                ];
            }

            // Check if ticket has this tag
            $ticketTag = $this->em->getConnection()->executeQuery(
                "SELECT id FROM ticket_tags WHERE ticket_id = ? AND tag_id = ?",
                [$ticketId, $tag->getId()]
            )->fetchAssociative();

            if (!$ticketTag) {
                // Tag not attached to ticket - return success
                return [
                    'ticket_id' => $ticketId,
                    'tag_name' => $normalizedName,
                    'action' => 'not_attached',
                ];
            }

            // Remove tag
            $this->em->getConnection()->executeStatement(
                "DELETE FROM ticket_tags WHERE ticket_id = ? AND tag_id = ?",
                [$ticketId, $tag->getId()]
            );

            // Emit event
            $event = new TicketEvent($ticketId, 'tag_removed', $source);
            $this->em->persist($event);
            $this->em->flush();

            return [
                'ticket_id' => $ticketId,
                'tag_name' => $normalizedName,
                'action' => 'removed',
            ];
        });
    }
}