<?php

namespace App\Domain\Conversation;

use App\Domain\Contact\Contact;

interface ConversationRepositoryInterface
{
    public function findOpenByContact(Contact $contact): ?Conversation;
    public function findById(int $id): ?Conversation;
}
