<?php

namespace App\Application\UseCase;

use App\Application\DTO\ContactDetailDTO;
use App\Domain\Contact\Contact;
use App\Domain\Contact\ContactRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class UpdateContactUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactRepositoryInterface $contactRepository,
    ) {}

    public function execute(int $id, array $data): ContactDetailDTO
    {
        $contact = $this->contactRepository->findById($id);

        if (!$contact) {
            throw new \InvalidArgumentException('Contact not found');
        }

        if (isset($data['name'])) {
            $contact->setName($data['name']);
        }

        if (isset($data['phone'])) {
            $phone = preg_replace('/[^0-9]/', '', $data['phone']);
            if (strlen($phone) < 10) {
                throw new \InvalidArgumentException('Invalid phone number');
            }

            $existing = $this->contactRepository->findOneByPhone($phone);
            if ($existing && $existing->getId() !== $id) {
                throw new \InvalidArgumentException('Another contact with this phone already exists');
            }

            $contact->setPhone($phone);
        }

        if (isset($data['status'])) {
            if (!in_array($data['status'], ['active', 'inactive'])) {
                throw new \InvalidArgumentException('Invalid status. Must be "active" or "inactive"');
            }
            $contact->setStatus($data['status']);
        }

        if (isset($data['channel'])) {
            if (!in_array($data['channel'], ['whatsapp', 'other'])) {
                throw new \InvalidArgumentException('Invalid channel. Must be "whatsapp" or "other"');
            }
            $contact->setChannel($data['channel']);
        }

        if (array_key_exists('owner_id', $data)) {
            if ($data['owner_id'] === null) {
                $contact->setOwnerId(null);
            } elseif (is_numeric($data['owner_id'])) {
                $contact->setOwnerId((int) $data['owner_id']);
            }
        }

        if (array_key_exists('email', $data)) {
            $contact->setEmail($data['email'] ?? null);
        }

        $contact->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

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