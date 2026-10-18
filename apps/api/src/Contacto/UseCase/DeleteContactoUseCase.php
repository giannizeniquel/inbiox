<?php

namespace App\Contacto\UseCase;

use App\Contacto\Contacto;
use App\Contacto\Repository\ContactoRepository;
use Doctrine\ORM\EntityManagerInterface;

class DeleteContactoUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactoRepository $contactRepository,
    ) {}

    public function execute(int $id): void
    {
        $contact = $this->contactRepository->findById($id);

        if (!$contact) {
            throw new \InvalidArgumentException('Contact not found');
        }

        // Soft delete: set status to inactive
        $contact->setEstado('inactive');
        $contact->setActualizadoAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }
}