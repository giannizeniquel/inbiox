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
        private ContactoRepository $contactRepository,
    ) {}

    public function execute(array $data): DetalleDeContactoDTO
    {
        if (empty($data['phone'])) {
            throw new \InvalidArgumentException('Phone is required');
        }

        $phone = NormalizadorDeTelefono::normalizar($data['phone']);

        $existing = $this->contactRepository->findOneByTelefono($phone);
        if ($existing) {
            throw new \InvalidArgumentException('Contact with this phone already exists');
        }

        $contact = new Contacto();
        $contact->setTelefono($phone);

        if (isset($data['name'])) {
            $contact->setNombre($data['name']);
        }

        if (isset($data['status']) && in_array($data['status'], ['active', 'inactive'])) {
            $contact->setEstado($data['status']);
        }

        if (isset($data['channel']) && in_array($data['channel'], ['whatsapp', 'other'])) {
            $contact->setCanal($data['channel']);
        }

        if (isset($data['owner_id']) && is_numeric($data['owner_id'])) {
            $contact->setResponsableId((int) $data['owner_id']);
        }

        if (isset($data['email'])) {
            $contact->setEmail($data['email']);
        }

        $this->entityManager->persist($contact);

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
            id: $contact->getId(),
            phone: $contact->getTelefono(),
            name: $contact->getNombre(),
            status: $contact->getEstado(),
            channel: $contact->getCanal(),
            ownerId: $contact->getResponsableId(),
            email: $contact->getEmail(),
            timestamps: [
                'created_at' => $contact->getCreadoAt()->format('Y-m-d H:i:s'),
                'updated_at' => $contact->getActualizadoAt()->format('Y-m-d H:i:s'),
            ],
        );
    }
}