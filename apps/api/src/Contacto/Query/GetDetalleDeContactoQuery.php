<?php

namespace App\Contacto\Query;

use App\Contacto\Repository\ContactoRepository;

class GetDetalleDeContactoQuery
{
    public function __construct(
        private ContactoRepository $contactRepository,
    ) {}

    public function execute(int $id): ?DetalleDeContactoDTO
    {
        $row = $this->contactRepository->findFilaDeDetalle($id);

        if (!$row) {
            return null;
        }

        return new DetalleDeContactoDTO(
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