<?php

namespace App\Contacto\UseCase;

use App\Contacto\Query\DetalleDeContactoDTO;
use App\Contacto\Contacto;
use App\Contacto\Repository\ContactoRepository;
use Doctrine\ORM\EntityManagerInterface;

class UpdateContactoUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactoRepository $contactoRepository,
    ) {}

    public function execute(int $id, array $data): DetalleDeContactoDTO
    {
        $contacto = $this->contactoRepository->findById($id);

        if (!$contacto) {
            throw new \InvalidArgumentException('Contact not found');
        }

        if (isset($data['name'])) {
            $contacto->setNombre($data['name']);
        }

        if (isset($data['phone'])) {
            $telefono = '+' . preg_replace('/[^0-9]/', '', $data['phone']);
            if (strlen($telefono) < 10) {
                throw new \InvalidArgumentException('Invalid phone number');
            }

            $existing = $this->contactoRepository->findOneByTelefono($telefono);
            if ($existing && $existing->getId() !== $id) {
                throw new \InvalidArgumentException('Another contact with this phone already exists');
            }

            $contacto->setTelefono($telefono);
        }

        if (isset($data['status'])) {
            if (!in_array($data['status'], ['active', 'inactive'])) {
                throw new \InvalidArgumentException('Invalid status. Must be "active" or "inactive"');
            }
            $contacto->setEstado($data['status']);
        }

        if (isset($data['channel'])) {
            if (!in_array($data['channel'], ['whatsapp', 'other'])) {
                throw new \InvalidArgumentException('Invalid channel. Must be "whatsapp" or "other"');
            }
            $contacto->setCanal($data['channel']);
        }

        if (array_key_exists('owner_id', $data)) {
            if ($data['owner_id'] === null) {
                $contacto->setResponsableId(null);
            } elseif (is_numeric($data['owner_id'])) {
                $contacto->setResponsableId((int) $data['owner_id']);
            }
        }

        if (array_key_exists('email', $data)) {
            $contacto->setEmail($data['email'] ?? null);
        }

        $contacto->setActualizadoAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new DetalleDeContactoDTO(
            id: $contacto->getId(),
            telefono: $contacto->getTelefono(),
            nombre: $contacto->getNombre(),
            estado: $contacto->getEstado(),
            canal: $contacto->getCanal(),
            responsableId: $contacto->getResponsableId(),
            email: $contacto->getEmail(),
            marcasDeTiempo: [
                'created_at' => $contacto->getCreadoAt()->format('Y-m-d H:i:s'),
                'updated_at' => $contacto->getActualizadoAt()->format('Y-m-d H:i:s'),
            ],
        );
    }
}