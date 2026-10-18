<?php

namespace App\Ticket\Repository;

use App\Ticket\Etiqueta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

class EtiquetaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Etiqueta::class);
    }

    public function findById(int $id): ?Etiqueta
    {
        return $this->find($id);
    }

    public function findByNombre(string $nombre): ?Etiqueta
    {
        return $this->findOneBy(['nombre' => Etiqueta::normalizar($nombre)]);
    }

    public function findAll(): array
    {
        return $this->findAll();
    }

    /**
     * Persist and flush a tag entity
     */
    public function guardar(Etiqueta $etiqueta): void
    {
        $this->getEntityManager()->persist($etiqueta);
        $this->getEntityManager()->flush();
    }

    /**
     * Whether a `ticket_tags` row links this ticket and tag.
     */
    public function isAsociadaATicket(int $ticketId, int $etiquetaId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('1')
            ->from('ticket_tags')
            ->where('ticket_id = :ticketId')
            ->andWhere('tag_id = :tagId')
            ->setParameter('ticketId', $ticketId)
            ->setParameter('tagId', $etiquetaId)
            ->fetchOne();
    }

    /**
     * Etiqueta names attached to each of these tickets, ordered by ticket id and
     * then by the order the tags were attached (`ticket_tags.id`).
     *
     * @param int[] $ticketIds
     * @return list<array{ticket_id: mixed, name: string}>
     */
    public function findNombresByTicketIds(array $ticketIds): array
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
