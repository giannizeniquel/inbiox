<?php

namespace App\Repository;

use App\Domain\Tag\Tag;
use App\Domain\Tag\TagRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TagRepository extends ServiceEntityRepository implements TagRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function findById(int $id): ?Tag
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?Tag
    {
        return $this->findOneBy(['name' => Tag::normalize($name)]);
    }

    public function findAll(): array
    {
        return $this->findAll();
    }

    /**
     * Persist and flush a tag entity
     */
    public function save(Tag $tag): void
    {
        $this->getEntityManager()->persist($tag);
        $this->getEntityManager()->flush();
    }
}