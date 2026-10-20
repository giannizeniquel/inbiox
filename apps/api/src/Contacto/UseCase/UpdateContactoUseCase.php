<?php

namespace App\Contacto\UseCase;

use App\Contacto\Query\DetalleDeContactoDTO;
use App\Contacto\CanalDeContacto;
use App\Contacto\Contacto;
use App\Contacto\EstadoDeContacto;
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

        if (isset($data['nombre'])) {
            $contacto->setNombre($data['nombre']);
        }

        if (isset($data['telefono'])) {
            $telefono = '+' . preg_replace('/[^0-9]/', '', $data['telefono']);
            if (strlen($telefono) < 10) {
                throw new \InvalidArgumentException('Invalid phone number');
            }

            $existing = $this->contactoRepository->findOneByTelefono($telefono);
            if ($existing && $existing->getId() !== $id) {
                throw new \InvalidArgumentException('Another contact with this phone already exists');
            }

            $contacto->setTelefono($telefono);
        }

        if (isset($data['estado'])) {
            if (!is_string($data['estado']) || EstadoDeContacto::tryFrom($data['estado']) === null) {
                throw new \InvalidArgumentException('Invalid status. Must be "activo" or "inactivo"');
            }
            $contacto->setEstado($data['estado']);
        }

        if (isset($data['canal'])) {
            if (!is_string($data['canal']) || CanalDeContacto::tryFrom($data['canal']) === null) {
                throw new \InvalidArgumentException('Invalid channel. Must be "whatsapp" or "otro"');
            }
            $contacto->setCanal($data['canal']);
        }

        if (array_key_exists('responsableId', $data)) {
            if ($data['responsableId'] === null) {
                $contacto->setResponsableId(null);
            } elseif (is_numeric($data['responsableId'])) {
                $contacto->setResponsableId((int) $data['responsableId']);
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
                'creadoAt' => $contacto->getCreadoAt()->format('Y-m-d H:i:s'),
                'actualizadoAt' => $contacto->getActualizadoAt()->format('Y-m-d H:i:s'),
            ],
        );
    }
}