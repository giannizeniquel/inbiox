<?php

namespace App\Contacto\UseCase;

use App\Contacto\Contacto;
use App\Contacto\EstadoDeContacto;
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

        // Borrado lógico: pasa el estado a inactive
        $contacto->setEstado(EstadoDeContacto::INACTIVO->value);
        $contacto->setActualizadoAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }
}