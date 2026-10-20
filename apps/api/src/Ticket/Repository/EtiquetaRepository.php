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
     * Persiste y hace flush de una entidad de etiqueta
     */
    public function guardar(Etiqueta $etiqueta): void
    {
        $this->getEntityManager()->persist($etiqueta);
        $this->getEntityManager()->flush();
    }

    /**
     * Indica si una fila de `ticket_tags` vincula este ticket y esta etiqueta.
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
     * Nombres de las etiquetas asociadas a cada uno de estos tickets, ordenados por id de ticket y
     * luego por el orden en que se asociaron las etiquetas (`ticket_tags.id`).
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
