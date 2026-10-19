<?php

namespace App\Conversacion\Repository;

use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Conversacion\EstadoDeConversacion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConversacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversacion::class);
    }

    public function findAbiertaByContacto(Contacto $contact, ?string $phoneNumberId = null): ?Conversacion
    {
        $criteria = ['contacto' => $contact, 'estado' => EstadoDeConversacion::ABIERTA->value];
        if ($phoneNumberId !== null) {
            $criteria['phoneNumberId'] = $phoneNumberId;
        }
        return $this->findOneBy($criteria);
    }

    public function findById(int $id): ?Conversacion
    {
        return $this->find($id);
    }
}