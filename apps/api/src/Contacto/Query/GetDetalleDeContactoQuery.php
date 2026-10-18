<?php

namespace App\Contacto\Query;

use App\Contacto\Repository\ContactoRepository;

class GetDetalleDeContactoQuery
{
    public function __construct(
        private ContactoRepository $contactoRepository,
    ) {}

    public function execute(int $id): ?DetalleDeContactoDTO
    {
        $row = $this->contactoRepository->findFilaDeDetalle($id);

        if (!$row) {
            return null;
        }

        return new DetalleDeContactoDTO(
            id: (int) $row['id'],
            telefono: $row['phone'],
            nombre: $row['name'],
            estado: $row['status'],
            canal: $row['channel'],
            responsableId: $row['owner_id'] ? (int) $row['owner_id'] : null,
            email: $row['email'],
            marcasDeTiempo: [
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
        );
    }
}