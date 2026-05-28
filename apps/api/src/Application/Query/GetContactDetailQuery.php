<?php

namespace App\Application\Query;

use App\Application\DTO\ContactDetailDTO;
use App\Domain\Contact\ContactRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class GetContactDetailQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactRepositoryInterface $contactRepository,
    ) {}

    public function execute(int $id): ?ContactDetailDTO
    {
        $conn = $this->entityManager->getConnection();

        $sql = "SELECT c.id, c.phone, c.name, c.status, c.channel, c.owner_id, c.email, c.created_at, c.updated_at
                FROM contacts c
                WHERE c.id = ?";

        $stmt = $conn->executeQuery($sql, [$id]);
        $row = $stmt->fetchAssociative();

        if (!$row) {
            return null;
        }

        return new ContactDetailDTO(
            id: (int) $row['id'],
            phone: $row['phone'],
            name: $row['name'],
            status: $row['status'],
            channel: $row['channel'],
            ownerId: $row['owner_id'] ? (int) $row['owner_id'] : null,
            email: $row['email'],
            timestamps: [
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
        );
    }
}