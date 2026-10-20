<?php

namespace App\Contacto\Repository;

use App\Asignacion\TipoDeAsignacion;
use App\Compartido\Outbox\TipoDeEventoDeOutbox;
use App\Contacto\Contacto;
use App\Ticket\TipoDeEventoDeTicket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class ContactoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contacto::class);
    }

    public function findOneByTelefono(string $telefono): ?Contacto
    {
        return $this->findOneBy(['telefono' => $telefono]);
    }

    public function findById(int $id): ?Contacto
    {
        return $this->find($id);
    }

    /**
     * Una página de la lista de contactos, los actualizados más recientemente primero (los empates por id,
     * el más nuevo primero). Los valores vacíos y los estados o canales desconocidos no agregan
     * filtro; `$search` busca en el nombre o el teléfono como subcadena.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginaDeLista(
        ?string $search,
        ?string $estado,
        ?string $canal,
        ?int $responsableId,
        ?string $email,
        int $limit,
        int $offset,
    ): array {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('c.id', 'c.phone', 'c.name', 'c.status', 'c.channel', 'c.owner_id', 'c.email', 'c.created_at', 'c.updated_at')
            ->from('contacts', 'c');

        $this->aplicarFiltrosDeLista($qb, $search, $estado, $canal, $responsableId, $email);

        return $qb->orderBy('c.updated_at', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->fetchAllAssociative();
    }

    /**
     * Cantidad total de contactos que cumplen los mismos filtros que findPaginaDeLista().
     */
    public function countLista(?string $search, ?string $estado, ?string $canal, ?int $responsableId, ?string $email): int
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('contacts', 'c');

        $this->aplicarFiltrosDeLista($qb, $search, $estado, $canal, $responsableId, $email);

        return (int) $qb->fetchOne();
    }

    /**
     * Las columnas del contacto que se muestran en el detalle del contacto, o false cuando el
     * contacto no existe.
     *
     * @return array<string, mixed>|false
     */
    public function findFilaDeDetalle(int $id): array|false
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('c.id', 'c.phone', 'c.name', 'c.status', 'c.channel', 'c.owner_id', 'c.email', 'c.created_at', 'c.updated_at')
            ->from('contacts', 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();
    }

    /**
     * La línea de tiempo del contacto, la más reciente primero, como máximo `$limit` filas: eventos
     * de outbox de ticket creado/actualizado, asignaciones y los tipos permitidos de
     * `ticket_events` (cierre/reapertura por la ventana de 24 h de WhatsApp y los seis
     * tipos de auditoría de resolución/cierre manual). Opcionalmente, solo los eventos anteriores a
     * `$before` y solo los tickets de un espacio de trabajo (`$phoneNumberId`).
     *
     * SQL nativo porque el QueryBuilder de DBAL no puede expresar un UNION ALL dentro de
     * una tabla derivada con un ORDER BY/LIMIT exterior, y DQL no tiene UNION en
     * absoluto. Los filtros opcionales se escriben como `(:param IS NULL OR ...)` para que
     * la sentencia sea un único string fijo con parámetros enlazados.
     *
     * @return list<array{type: string, occurred_at: string, content: null, ticket_id: mixed, ticket_status: ?string, assigned_to: ?string, agent_id: mixed, direction: null}>
     */
    public function findFilasDeLineaDeTiempo(int $contactoId, ?string $before, int $limit, ?string $phoneNumberId): array
    {
        $sql = <<<'SQL'
        SELECT type, occurred_at, content, ticket_id, ticket_status, assigned_to, agent_id, direction
        FROM (
            SELECT
                CASE
                    WHEN oe.event_type IN (:tipos_ticket_creado) THEN 'ticket.created'
                    WHEN oe.event_type IN (:tipos_ticket_actualizado) THEN 'ticket.status_changed'
                END AS type,
                oe.created_at AS occurred_at,
                NULL AS content,
                t.id AS ticket_id,
                t.status AS ticket_status,
                NULL AS assigned_to,
                NULL AS agent_id,
                NULL AS direction
            FROM outbox_events oe
            JOIN tickets t ON oe.aggregate_id = t.id
            WHERE t.contact_id = :contact_id
              AND oe.aggregate_type = 'ticket'
              AND oe.event_type IN (:tipos_ticket_creado, :tipos_ticket_actualizado)
              AND (:before_date IS NULL OR oe.created_at < :before_date)
              AND (:phone_number_id IS NULL OR t.phone_number_id = :phone_number_id)

            UNION ALL

            SELECT
                CASE
                    WHEN a.type = :tipo_reasignacion THEN 'ticket.reassigned'
                    ELSE 'ticket.assigned'
                END AS type,
                a.assigned_at AS occurred_at,
                NULL AS content,
                t.id AS ticket_id,
                NULL AS ticket_status,
                ag.name AS assigned_to,
                ag.id AS agent_id,
                NULL AS direction
            FROM assignments a
            JOIN tickets t ON a.ticket_id = t.id
            LEFT JOIN agents ag ON a.agent_id = ag.id
            WHERE t.contact_id = :contact_id
              AND (:before_date IS NULL OR a.assigned_at < :before_date)
              AND (:phone_number_id IS NULL OR t.phone_number_id = :phone_number_id)

            UNION ALL

            SELECT
                CASE te.type
                    WHEN :window_expired_closed THEN 'ticket.window_expired_closed'
                    WHEN :window_reopened THEN 'ticket.window_reopened'
                    WHEN :manual_resolved_owner THEN 'ticket.manual_resolved_owner'
                    WHEN :manual_resolved_override THEN 'ticket.manual_resolved_override'
                    WHEN :manual_resolved_unowned THEN 'ticket.manual_resolved_unowned'
                    WHEN :manual_closed_owner THEN 'ticket.manual_closed_owner'
                    WHEN :manual_closed_override THEN 'ticket.manual_closed_override'
                    WHEN :manual_closed_unowned THEN 'ticket.manual_closed_unowned'
                END AS type,
                te.created_at AS occurred_at,
                NULL AS content,
                t.id AS ticket_id,
                t.status AS ticket_status,
                CASE WHEN u.id IS NULL THEN NULL ELSE CONCAT(
                    CASE
                        WHEN JSON_CONTAINS(u.roles, '"ROLE_ADMIN"') THEN 'Admin'
                        WHEN JSON_CONTAINS(u.roles, '"ROLE_SUPERVISOR"') THEN 'Sup'
                        ELSE 'Agente'
                    END,
                    ' ', COALESCE(ag.name, u.email)
                ) END AS assigned_to,
                ag.id AS agent_id,
                NULL AS direction
            FROM ticket_events te
            JOIN tickets t ON te.ticket_id = t.id
            LEFT JOIN `user` u ON te.source = CONCAT('u:', u.id)
            LEFT JOIN agents ag ON ag.user_id = u.id
            WHERE t.contact_id = :contact_id
              AND te.type IN (:allowed_types)
              AND (:before_date IS NULL OR te.created_at < :before_date)
              AND (:phone_number_id IS NULL OR t.phone_number_id = :phone_number_id)
        ) AS timeline
        ORDER BY occurred_at DESC
        LIMIT :limit
        SQL;

        // Lista permitida D6: solo se muestran los tipos de evento de cumplimiento de la ventana de 24 h de WhatsApp
        // más los seis tipos de auditoría de cierre/resolución manual.
        $typeParams = [
            'window_expired_closed' => TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO,
            'window_reopened' => TipoDeEventoDeTicket::VENTANA_REABIERTO,
            'manual_resolved_owner' => TipoDeEventoDeTicket::MANUAL_RESUELTO_RESPONSABLE,
            'manual_resolved_override' => TipoDeEventoDeTicket::MANUAL_RESUELTO_FORZADO,
            'manual_resolved_unowned' => TipoDeEventoDeTicket::MANUAL_RESUELTO_SIN_RESPONSABLE,
            'manual_closed_owner' => TipoDeEventoDeTicket::MANUAL_CERRADO_RESPONSABLE,
            'manual_closed_override' => TipoDeEventoDeTicket::MANUAL_CERRADO_FORZADO,
            'manual_closed_unowned' => TipoDeEventoDeTicket::MANUAL_CERRADO_SIN_RESPONSABLE,
        ];

        // Los tipos del outbox incluyen el alias legado (nombre de clase) hasta la etapa 3 del contrato.
        $params = $typeParams + [
            'tipos_ticket_creado' => TipoDeEventoDeOutbox::TICKET_CREADO->valoresGuardados(),
            'tipos_ticket_actualizado' => TipoDeEventoDeOutbox::TICKET_ACTUALIZADO->valoresGuardados(),
            'tipo_reasignacion' => TipoDeAsignacion::REASIGNACION->value,
            'contact_id' => $contactoId,
            'allowed_types' => array_values($typeParams),
            'before_date' => $before,
            'phone_number_id' => $phoneNumberId,
            'limit' => $limit,
        ];
        $types = [
            'tipos_ticket_creado' => ArrayParameterType::STRING,
            'tipos_ticket_actualizado' => ArrayParameterType::STRING,
            'contact_id' => ParameterType::INTEGER,
            'allowed_types' => ArrayParameterType::STRING,
            'before_date' => ParameterType::STRING,
            'phone_number_id' => ParameterType::STRING,
            'limit' => ParameterType::INTEGER,
        ];

        return $this->getEntityManager()->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAllAssociative();
    }

    private function aplicarFiltrosDeLista(
        QueryBuilder $qb,
        ?string $search,
        ?string $estado,
        ?string $canal,
        ?int $responsableId,
        ?string $email,
    ): void {
        if ($search) {
            $qb->andWhere('(c.name LIKE :search OR c.phone LIKE :search)')
                ->setParameter('search', "%{$search}%");
        }

        if ($estado && in_array($estado, ['active', 'inactive'])) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $estado);
        }

        if ($canal && in_array($canal, ['whatsapp', 'other'])) {
            $qb->andWhere('c.channel = :channel')
                ->setParameter('channel', $canal);
        }

        if ($responsableId) {
            $qb->andWhere('c.owner_id = :ownerId')
                ->setParameter('ownerId', $responsableId);
        }

        if ($email) {
            $qb->andWhere('c.email = :email')
                ->setParameter('email', $email);
        }
    }
}
