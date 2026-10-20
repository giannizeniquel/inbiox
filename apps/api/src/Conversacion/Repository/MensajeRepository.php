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

    public function findByConversacion(Conversacion $conversacion): array
    {
        return $this->findBy(['conversacion' => $conversacion]);
    }

    public function findById(int $id): ?Mensaje
    {
        return $this->find($id);
    }

    /**
     * Hasta `$maxResults` mensajes de una conversación, del más antiguo al más nuevo
     * (empates por id). Con un cursor, solo los mensajes posteriores a él: `created_at`
     * mayor, o el mismo `created_at` y un id mayor.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginaAscendente(int $conversacionId, mixed $cursorCreadoAt, ?int $cursorId, int $maxResults): array
    {
        $qb = $this->createPaginaQueryBuilder($conversacionId);

        if ($cursorId !== null) {
            $qb->andWhere('(created_at > :cursorCreatedAt OR (created_at = :cursorCreatedAt AND id > :cursorId))')
                ->setParameter('cursorCreatedAt', $cursorCreadoAt)
                ->setParameter('cursorId', $cursorId);
        }

        return $qb->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($maxResults)
            ->fetchAllAssociative();
    }

    /**
     * Hasta `$maxResults` mensajes de una conversación, del más nuevo al más antiguo
     * (empates por id), saltando `$offset` filas cuando es positivo. Con un cursor, solo
     * los mensajes anteriores a él: `created_at` menor, o el mismo `created_at` y
     * un id menor.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginaDescendente(int $conversacionId, mixed $cursorCreadoAt, ?int $cursorId, int $maxResults, int $offset = 0): array
    {
        $qb = $this->createPaginaQueryBuilder($conversacionId);

        if ($cursorId !== null) {
            $qb->andWhere('(created_at < :cursorCreatedAt OR (created_at = :cursorCreatedAt AND id < :cursorId))')
                ->setParameter('cursorCreatedAt', $cursorCreadoAt)
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

    private function createPaginaQueryBuilder(int $conversacionId): QueryBuilder
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id', 'content', 'direction', 'created_at', 'media', 'sender_agent_id', 'sender_type')
            ->from('messages')
            ->where('conversation_id = :conversationId')
            ->setParameter('conversationId', $conversacionId);
    }
}
