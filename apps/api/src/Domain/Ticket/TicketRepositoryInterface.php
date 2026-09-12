<?php

namespace App\Domain\Ticket;

use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;

interface TicketRepositoryInterface
{
    public function findOpenByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket;
    public function findNotClosedByContact(Contact $contact, ?string $phoneNumberId = null): ?Ticket;
    public function findById(int $id): ?Ticket;

    /**
     * Find a ticket for update: a pessimistic-write locking read, hydrated
     * from latest-committed data (not a stale consistent-read snapshot).
     * MUST be the FIRST statement of any transaction that decides ownership
     * (design.md D1/D2).
     */
    public function findByIdForUpdate(int $id): ?Ticket;
    public function findByConversation(Conversation $conversation): ?Ticket;
}
