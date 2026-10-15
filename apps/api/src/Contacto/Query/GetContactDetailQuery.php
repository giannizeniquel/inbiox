<?php

namespace App\Contacto\Query;

use App\Contacto\Repository\ContactRepository;

class GetContactDetailQuery
{
    public function __construct(
        private ContactRepository $contactRepository,
    ) {}

    public function execute(int $id): ?ContactDetailDTO
    {
        $row = $this->contactRepository->findDetailRow($id);

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