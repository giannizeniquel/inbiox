<?php

namespace App\Contacto\UseCase;

use App\Contacto\Contact;
use App\Contacto\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;

class DeleteContactUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactRepository $contactRepository,
    ) {}

    public function execute(int $id): void
    {
        $contact = $this->contactRepository->findById($id);

        if (!$contact) {
            throw new \InvalidArgumentException('Contact not found');
        }

        // Soft delete: set status to inactive
        $contact->setStatus('inactive');
        $contact->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }
}