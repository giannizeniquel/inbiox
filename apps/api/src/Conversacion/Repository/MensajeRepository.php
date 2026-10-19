<?php

namespace App\Conversacion\Repository;

use App\Conversacion\Conversacion;
use App\Conversacion\Mensaje;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class MensajeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mensaje::class);
    }

    public function findByExternalId(string $externalId): ?Mensaje
    {
        return $this->findOneBy(['externalId' => $externalId]);
    }

    public function findByConversacion(Conversacion $conversation): array
    {
        return $this->findBy(['conversacion' => $conversation]);
    }

    public function findById(int $id): ?Mensaje
    {
        return $this->find($id);
    }

    /**
     * Up to `$maxResults` messages of a conversation, oldest first (ties by
     * id). With a cursor, only messages after it: later `created_at`, or the
     * same `created_at` and a higher id.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginaAscendente(int $conversationId, mixed $cursorCreatedAt, ?int $cursorId, int $maxResults): array
    {
        $qb = $this->createPaginaQueryBuilder($conversationId);

        if ($cursorId !== null) {
            $qb->andWhere('(created_at > :cursorCreatedAt OR (created_at = :cursorCreatedAt AND id > :cursorId))')
                ->setParameter('cursorCreatedAt', $cursorCreatedAt)
                ->setParameter('cursorId', $cursorId);
        }

        return $qb->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($maxResults)
            ->fetchAllAssociative();
    }

    /**
     * Up to `$maxResults` messages of a conversation, newest first (ties by
     * id), skipping `$offset` rows when it is positive. With a cursor, only
     * messages before it: earlier `created_at`, or the same `created_at` and
     * a lower id.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginaDescendente(int $conversationId, mixed $cursorCreatedAt, ?int $cursorId, int $maxResults, int $offset = 0): array
    {
        $qb = $this->createPaginaQueryBuilder($conversationId);

        if ($cursorId !== null) {
            $qb->andWhere('(created_at < :cursorCreatedAt OR (created_at = :cursorCreatedAt AND id < :cursorId))')
                ->setParameter('cursorCreatedAt', $cursorCreatedAt)
                ->setParameter('cursorId', $cursorId);
        }

        $qb->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($maxResults);

        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        return $qb->fetchAllAssociative();
    }

    private function createPaginaQueryBuilder(int $conversationId): QueryBuilder
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id', 'content', 'direction', 'created_at', 'media', 'sender_agent_id', 'sender_type')
            ->from('messages')
            ->where('conversation_id = :conversationId')
            ->setParameter('conversationId', $conversationId);
    }
}
