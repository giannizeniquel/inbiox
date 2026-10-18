<?php

namespace App\Contacto\UseCase;

use App\Contacto\Contacto;
use App\Contacto\Repository\ContactoRepository;
use Doctrine\ORM\EntityManagerInterface;

class DeleteContactoUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactoRepository $contactoRepository,
    ) {}

    public function execute(int $id): void
    {
        $contacto = $this->contactoRepository->findById($id);

        if (!$contacto) {
            throw new \InvalidArgumentException('Contact not found');
        }

        // Soft delete: set status to inactive
        $contacto->setEstado('inactive');
        $contacto->setActualizadoAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }
}