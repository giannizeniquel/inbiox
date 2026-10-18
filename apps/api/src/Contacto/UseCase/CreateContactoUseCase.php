<?php

namespace App\Contacto\UseCase;

use App\Contacto\Query\DetalleDeContactoDTO;
use App\Contacto\Contacto;
use App\Contacto\Repository\ContactoRepository;
use App\Contacto\NormalizadorDeTelefono;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

class CreateContactoUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactoRepository $contactoRepository,
    ) {}

    public function execute(array $data): DetalleDeContactoDTO
    {
        if (empty($data['phone'])) {
            throw new \InvalidArgumentException('Phone is required');
        }

        $telefono = NormalizadorDeTelefono::normalizar($data['phone']);

        $existing = $this->contactoRepository->findOneByTelefono($telefono);
        if ($existing) {
            throw new \InvalidArgumentException('Contact with this phone already exists');
        }

        $contacto = new Contacto();
        $contacto->setTelefono($telefono);

        if (isset($data['name'])) {
            $contacto->setNombre($data['name']);
        }

        if (isset($data['status']) && in_array($data['status'], ['active', 'inactive'])) {
            $contacto->setEstado($data['status']);
        }

        if (isset($data['channel']) && in_array($data['channel'], ['whatsapp', 'other'])) {
            $contacto->setCanal($data['channel']);
        }

        if (isset($data['owner_id']) && is_numeric($data['owner_id'])) {
            $contacto->setResponsableId((int) $data['owner_id']);
        }

        if (isset($data['email'])) {
            $contacto->setEmail($data['email']);
        }

        $this->entityManager->persist($contacto);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Defense-in-depth: the pre-check above closes this race in the
            // overwhelming majority of cases, but a true concurrent request can
            // still win the DB-level unique constraint at flush time. Convert it
            // into the same deterministic error the pre-check throws instead of
            // letting it propagate uncaught (which would close the EntityManager).
            throw new \InvalidArgumentException('Contact with this phone already exists');
        }

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