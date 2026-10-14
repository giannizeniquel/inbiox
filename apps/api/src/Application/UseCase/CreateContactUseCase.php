<?php

namespace App\Application\UseCase;

use App\Application\DTO\ContactDetailDTO;
use App\Domain\Contact\Contact;
use App\Repository\ContactRepository;
use App\Domain\Contact\PhoneNormalizer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

class CreateContactUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactRepository $contactRepository,
    ) {}

    public function execute(array $data): ContactDetailDTO
    {
        if (empty($data['phone'])) {
            throw new \InvalidArgumentException('Phone is required');
        }

        $phone = PhoneNormalizer::normalize($data['phone']);

        $existing = $this->contactRepository->findOneByPhone($phone);
        if ($existing) {
            throw new \InvalidArgumentException('Contact with this phone already exists');
        }

        $contact = new Contact();
        $contact->setPhone($phone);

        if (isset($data['name'])) {
            $contact->setName($data['name']);
        }

        if (isset($data['status']) && in_array($data['status'], ['active', 'inactive'])) {
            $contact->setStatus($data['status']);
        }

        if (isset($data['channel']) && in_array($data['channel'], ['whatsapp', 'other'])) {
            $contact->setChannel($data['channel']);
        }

        if (isset($data['owner_id']) && is_numeric($data['owner_id'])) {
            $contact->setOwnerId((int) $data['owner_id']);
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

        return new ContactDetailDTO(
            id: $contact->getId(),
            phone: $contact->getPhone(),
            name: $contact->getName(),
            status: $contact->getStatus(),
            channel: $contact->getChannel(),
            ownerId: $contact->getOwnerId(),
            email: $contact->getEmail(),
            timestamps: [
                'created_at' => $contact->getCreatedAt()->format('Y-m-d H:i:s'),
                'updated_at' => $contact->getUpdatedAt()->format('Y-m-d H:i:s'),
            ],
        );
    }
}