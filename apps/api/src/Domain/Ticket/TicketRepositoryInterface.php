<?php

namespace App\Domain\Ticket;

use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;

interface TicketRepositoryInterface
{
    public function findOpenByContact(Contact $contact): ?Ticket;
    public function findNotClosedByContact(Contact $contact): ?Ticket;
    public function findById(int $id): ?Ticket;
    public function findByConversation(Conversation $conversation): ?Ticket;
}
