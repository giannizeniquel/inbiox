<?php

namespace App\Contacto\Repository;

use App\Contacto\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    public function findOneByPhone(string $phone): ?Contact
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    public function findById(int $id): ?Contact
    {
        return $this->find($id);
    }
}