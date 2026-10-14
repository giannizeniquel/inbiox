<?php

namespace App\Ticket\Repository;

use App\Ticket\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

class TagRepository extends ServiceEntityRepository
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

    /**
     * Whether a `ticket_tags` row links this ticket and tag.
     */
    public function isAttachedToTicket(int $ticketId, int $tagId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('1')
            ->from('ticket_tags')
            ->where('ticket_id = :ticketId')
            ->andWhere('tag_id = :tagId')
            ->setParameter('ticketId', $ticketId)
            ->setParameter('tagId', $tagId)
            ->fetchOne();
    }

    /**
     * Tag names attached to each of these tickets, ordered by ticket id and
     * then by the order the tags were attached (`ticket_tags.id`).
     *
     * @param int[] $ticketIds
     * @return list<array{ticket_id: mixed, name: string}>
     */
    public function findNamesByTicketIds(array $ticketIds): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('tt.ticket_id', 'tg.name')
            ->from('ticket_tags', 'tt')
            ->innerJoin('tt', 'tags', 'tg', 'tg.id = tt.tag_id')
            ->where('tt.ticket_id IN (:ticketIds)')
            ->orderBy('tt.ticket_id', 'ASC')
            ->addOrderBy('tt.id', 'ASC')
            ->setParameter('ticketIds', $ticketIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
    }
}
