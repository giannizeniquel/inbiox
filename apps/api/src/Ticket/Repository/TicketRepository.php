<?php

namespace App\Ticket\Repository;

use App\Ticket\TipoDeEventoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Ticket\FiltroDeBandeja;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Conversacion\DireccionDeMensaje;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

class TicketRepository extends ServiceEntityRepository
{
    /** Estados que cuentan como carga activa de un agente. */
    private const ESTADOS_ACTIVOS = [
        EstadoDeTicket::NUEVO->value,
        EstadoDeTicket::ACTIVO->value,
        EstadoDeTicket::EN_ESPERA->value,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findAbiertoByContacto(Contacto $contacto, ?string $phoneNumberId = null): ?Ticket
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.contacto = :contact')
            ->andWhere('t.estado IN (:statuses)')
            ->setParameter('contact', $contacto->getId() ?? 0)
            ->setParameter('statuses', [
                EstadoDeTicket::NUEVO->value,
                EstadoDeTicket::ACTIVO->value,
                EstadoDeTicket::EN_ESPERA->value,
            ]);

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phoneNumberId = :phoneNumberId')
               ->setParameter('phoneNumberId', $phoneNumberId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findNoCerradoByContacto(Contacto $contacto, ?string $phoneNumberId = null): ?Ticket
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.contacto = :contact')
            ->andWhere('t.estado IN (:statuses)')
            ->setParameter('contact', $contacto->getId() ?? 0)
            ->setParameter('statuses', [
                EstadoDeTicket::NUEVO->value,
                EstadoDeTicket::ACTIVO->value,
                EstadoDeTicket::EN_ESPERA->value,
                EstadoDeTicket::RESUELTO->value,
            ]);

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phoneNumberId = :phoneNumberId')
               ->setParameter('phoneNumberId', $phoneNumberId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findById(int $id): ?Ticket
    {
        return $this->find($id);
    }

    /**
     * Lectura con bloqueo: PESSIMISTIC_WRITE adquirido como parte del propio SELECT
     * (design.md D1). DEBE ser la PRIMERA sentencia de la transacción para que nada
     * más pueda abrir un snapshot de lectura consistente antes del bloqueo.
     *
     * Query::HINT_REFRESH obliga a Doctrine a sobrescribir cualquier copia obsoleta
     * del identity map con la fila recién bloqueada en lugar de reutilizarla en silencio.
     *
     * Descartado: `findById()` + `$em->lock()` (convención de AsignarTicketUseCase)
     * — con MySQL REPEATABLE READ, `findById()` abre el snapshot de lectura
     * consistente ANTES de adquirir el bloqueo, así que un `refresh()` posterior
     * sigue leyendo el snapshot previo al bloqueo. El TOCTOU sobrevive.
     */
    public function findByIdForUpdate(int $id): ?Ticket
    {
        return $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Busca un ticket `closed` de este contacto apto para la reapertura disparada
     * por el sistema: la fila MÁS RECIENTE de `ticket_events` del ticket debe ser del tipo
     * `window_expired_closed`. Un ticket sin eventos, con otro tipo de
     * último evento o cerrado por otro motivo no produce coincidencia —
     * sin cambios, así que `ProcesarMensajeEntranteUseCase` recurre a crear un
     * ticket nuevo, exactamente como lo hace hoy (design.md).
     *
     * DQL no puede expresar una subconsulta correlacionada con `ORDER BY ... LIMIT 1`, así que
     * esto usa la conexión directamente (precedente: `CambiarEstadoDeTicketUseCase`),
     * y luego rehidrata con `$this->find($id)` para que quien llama reciba una
     * entidad gestionada sobre la que pueda hacer `lock()`.
     */
    public function findCerradoPorVentanaVencidaByContacto(Contacto $contacto, ?string $phoneNumberId = null): ?Ticket
    {
        $sql = 'SELECT t.id
                FROM tickets t
                WHERE t.contact_id = :contact_id
                  AND t.status = :status
                  AND (SELECT te.type
                       FROM ticket_events te
                       WHERE te.ticket_id = t.id
                       ORDER BY te.created_at DESC, te.id DESC
                       LIMIT 1) = :window_expired_closed';
        $params = [
            'contact_id' => $contacto->getId() ?? 0,
            'status' => EstadoDeTicket::CERRADO->value,
            'window_expired_closed' => TipoDeEventoDeTicket::VENTANA_VENCIDA_CERRADO,
        ];

        if ($phoneNumberId !== null) {
            $sql .= ' AND t.phone_number_id = :phone_number_id';
            $params['phone_number_id'] = $phoneNumberId;
        }

        $sql .= ' ORDER BY t.updated_at DESC, t.id DESC LIMIT 1';

        $id = $this->getEntityManager()->getConnection()->fetchOne($sql, $params);

        return $id !== false ? $this->find((int) $id) : null;
    }

    public function findByConversacion(Conversacion $conversacion): ?Ticket
    {
        return $this->findOneBy(['conversacion' => $conversacion]);
    }

    /**
     * Cuenta los tickets activos asignados actualmente a un agente.
     *
     * Los tickets activos son los que tienen status IN ('nuevo', 'activo', 'en_espera').
     * Usa el índice idx_tickets_owner_status (assigned_agent_id, status).
     */
    public function countActivosDeAgente(int $agenteId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.agenteAsignadoId = :agentId')
            ->andWhere('t.estado IN (:statuses)')
            ->setParameter('agentId', $agenteId)
            ->setParameter('statuses', [
                EstadoDeTicket::NUEVO->value,
                EstadoDeTicket::ACTIVO->value,
                EstadoDeTicket::EN_ESPERA->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Lectura con bloqueo de la fila cruda de `tickets`: `SELECT * ... FOR UPDATE`.
     * Devuelve la fila como array asociativo, o false cuando no existe.
     *
     * SQL nativo porque DQL no puede hacerlo sin efectos secundarios: una lectura
     * DQL con PESSIMISTIC_WRITE hidrata (y con HINT_REFRESH sobrescribe) la
     * entidad Ticket gestionada, mientras que quienes llaman necesitan los últimos
     * valores confirmados de las columnas como array plano y sin tocar el identity map.
     * Se ejecuta sobre la conexión del EntityManager, así que se suma a la
     * transacción abierta de quien llama.
     *
     * @return array<string, mixed>|false
     */
    public function findFilaByIdForUpdate(int $id): array|false
    {
        return $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT * FROM tickets WHERE id = ? FOR UPDATE',
            [$id]
        )->fetchAssociative();
    }

    /**
     * Cambio de estado escrito por CambiarEstadoDeTicketUseCase::execute():
     * asigna status y updated_at, más cada timestamp de SLA que pida quien llama,
     * en este orden del SET: first_response_at, resolved_at, closed_at.
     */
    public function updateEstadoTrasTransicion(
        int $id,
        string $estado,
        bool $setPrimeraRespuesta,
        bool $setResuelto,
        bool $setCerrado,
    ): void {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('updated_at', 'NOW()');

        if ($setPrimeraRespuesta) {
            $qb->set('first_response_at', 'NOW()');
        }
        if ($setResuelto) {
            $qb->set('resolved_at', 'NOW()');
        }
        if ($setCerrado) {
            $qb->set('closed_at', 'NOW()');
        }

        $qb->where('id = :id')
            ->setParameter('status', $estado)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Escritura de cierre: status, closed_at = NOW(), updated_at = NOW().
     */
    public function marcarCerrado(int $id, string $estado): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('closed_at', 'NOW()')
            ->set('updated_at', 'NOW()')
            ->where('id = :id')
            ->setParameter('status', $estado)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Escritura de reapertura: status, closed_at = NULL, updated_at = NOW().
     */
    public function marcarReabierto(int $id, string $estado): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('status', ':status')
            ->set('closed_at', 'NULL')
            ->set('updated_at', 'NOW()')
            ->where('id = :id')
            ->setParameter('status', $estado)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Primera respuesta del agente sin cambio de estado:
     * first_response_at = NOW(), updated_at = NOW().
     */
    public function marcarPrimeraRespuesta(int $id): void
    {
        $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update('tickets')
            ->set('first_response_at', 'NOW()')
            ->set('updated_at', 'NOW()')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Tipo de la fila más reciente de `ticket_events` del ticket
     * (por created_at y luego por id), o false cuando no tiene eventos.
     */
    public function findUltimoTipoDeEvento(int $ticketId): string|false
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('type')
            ->from('ticket_events')
            ->where('ticket_id = :ticketId')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('ticketId', $ticketId)
            ->fetchOne();
    }

    /**
     * La columna `phone_number_id` del ticket, o null cuando el ticket no
     * existe o la columna es null. Se lee directamente de la tabla, así que
     * no toca la entidad gestionada.
     */
    public function findPhoneNumberId(int $id): ?string
    {
        $phoneNumberId = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('phone_number_id')
            ->from('tickets')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchOne();

        return $phoneNumberId === false || $phoneNumberId === null ? null : (string) $phoneNumberId;
    }

    /**
     * Ids de los tickets en alguno de `$estados` cuyo último mensaje entrante es
     * anterior a `$threshold` (`Y-m-d H:i:s`), los más antiguos primero, como máximo `$limit`.
     * Los tickets con `last_inbound_message_at` null nunca coinciden.
     *
     * @param string[] $estados
     * @return list<mixed>
     */
    public function findIdsConVentanaVencida(array $estados, string $threshold, int $limit): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id')
            ->from('tickets')
            ->where('status IN (:statuses)')
            ->andWhere('last_inbound_message_at IS NOT NULL')
            ->andWhere('last_inbound_message_at < :threshold')
            ->orderBy('last_inbound_message_at', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('statuses', $estados, ArrayParameterType::STRING)
            ->setParameter('threshold', $threshold)
            ->fetchFirstColumn();
    }

    /**
     * Una página de la lista de tickets de la bandeja (`ListTicketsQuery`): el ticket, su
     * contacto, su agente asignado y la cantidad de tickets activos de ese agente,
     * con el `updated_at` más reciente primero (los empates por id, el más nuevo primero).
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginaDeLista(FiltrosDeTicket $filtros, int $limit, int $offset): array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select(
                't.id', 't.status', 't.priority', 't.assigned_agent_id', 't.created_at', 't.updated_at',
                't.last_inbound_message_at',
                'c.phone', 'c.name AS contact_name', 'conv.id AS conv_id',
                'a.name AS agent_name', 'a.max_active_tickets',
                "(SELECT COUNT(*) FROM tickets t2 WHERE t2.assigned_agent_id = a.id AND t2.status IN (:activeStatuses)) AS current_active_tickets",
            )
            ->from('tickets', 't')
            ->innerJoin('t', 'conversations', 'conv', 't.conversation_id = conv.id')
            ->innerJoin('t', 'contacts', 'c', 'conv.contact_id = c.id')
            ->leftJoin('t', 'agents', 'a', 't.assigned_agent_id = a.id')
            ->setParameter('activeStatuses', self::ESTADOS_ACTIVOS, ArrayParameterType::STRING);

        $this->aplicarFiltrosDeLista($qb, $filtros);

        return $qb->orderBy('t.updated_at', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->fetchAllAssociative();
    }

    /**
     * Cantidad total de tickets que cumplen los filtros de la lista (no hacen falta joins).
     */
    public function countLista(FiltrosDeTicket $filtros): int
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('tickets', 't');

        $this->aplicarFiltrosDeLista($qb, $filtros);

        return (int) $qb->fetchOne();
    }

    /**
     * Mensajes entrantes posteriores al `last_read_at` del ticket (o todos
     * cuando es null), por conversación. Las conversaciones sin mensajes
     * no leídos no aparecen en el resultado.
     *
     * @param int[] $conversacionIds
     * @return list<array{conversation_id: mixed, cnt: mixed}>
     */
    public function countEntrantesNoLeidosByConversacion(array $conversacionIds): array
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('m2.conversation_id', 'COUNT(*) AS cnt')
            ->from('messages', 'm2')
            ->innerJoin('m2', 'tickets', 't2', 't2.conversation_id = m2.conversation_id')
            ->where('m2.conversation_id IN (:conversationIds)')
            ->andWhere('m2.direction = :inbound')
            ->andWhere("m2.created_at > COALESCE(t2.last_read_at, '1970-01-01 00:00:00')")
            ->groupBy('m2.conversation_id')
            ->setParameter('conversationIds', $conversacionIds, ArrayParameterType::INTEGER)
            ->setParameter('inbound', DireccionDeMensaje::ENTRANTE->value)
            ->fetchAllAssociative();
    }

    /**
     * Indica si el agente tiene un ticket nuevo, activo o en espera en el espacio de trabajo
     * que nunca se leyó o que tiene un mensaje entrante posterior a su última lectura
     * (`has_pending` en `GET /api/me/workspaces`).
     */
    public function hasPendientesDeAgente(string $phoneNumberId, int $agenteId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('1')
            ->from('tickets', 't')
            ->innerJoin('t', 'conversations', 'conv', 't.conversation_id = conv.id')
            ->where('t.phone_number_id = :phone')
            ->andWhere('t.assigned_agent_id = :agentId')
            ->andWhere('t.status IN (:statuses)')
            ->andWhere('t.last_read_at IS NULL OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = conv.id AND m.direction = :inbound AND m.created_at > t.last_read_at)')
            ->setParameter('phone', $phoneNumberId)
            ->setParameter('agentId', $agenteId)
            ->setParameter('statuses', self::ESTADOS_ACTIVOS, ArrayParameterType::STRING)
            ->setParameter('inbound', DireccionDeMensaje::ENTRANTE->value)
            ->setMaxResults(1)
            ->fetchOne();
    }

    /**
     * La fila de detalle del ticket (`GetDetalleDeTicketQuery`): ticket, contacto, la
     * cantidad de entrantes no leídos y el contenido del último mensaje, o false
     * cuando el ticket no existe.
     *
     * @return array<string, mixed>|false
     */
    public function findFilaDeDetalle(int $id): array|false
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select(
                't.id AS ticket_id', 't.status', 't.priority', 't.assigned_agent_id AS owner_id',
                't.created_at AS opened_at', 't.first_response_at', 't.resolved_at', 't.updated_at',
                't.last_inbound_message_at',
                'c.id AS contact_id', 'c.phone AS contact_phone', 'c.name AS contact_name',
                'conv.id AS conv_id',
                "(SELECT COUNT(*) FROM messages m2 WHERE m2.conversation_id = conv.id AND m2.direction = :inbound AND m2.created_at > COALESCE(t.last_read_at, '1970-01-01 00:00:00')) AS unread_count",
                '(SELECT m3.content FROM messages m3 WHERE m3.conversation_id = conv.id ORDER BY m3.created_at DESC LIMIT 1) AS last_message',
            )
            ->from('tickets', 't')
            ->innerJoin('t', 'conversations', 'conv', 't.conversation_id = conv.id')
            ->innerJoin('conv', 'contacts', 'c', 'conv.contact_id = c.id')
            ->where('t.id = :ticketId')
            ->setParameter('ticketId', $id)
            ->setParameter('inbound', DireccionDeMensaje::ENTRANTE->value)
            ->fetchAssociative();
    }

    /**
     * El `conversation_id` del ticket: false cuando el ticket no existe,
     * null cuando la columna es null.
     */
    public function findConversacionId(int $id): int|false|null
    {
        $conversacionId = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('conversation_id')
            ->from('tickets')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchOne();

        if ($conversacionId === false || $conversacionId === null) {
            return $conversacionId;
        }

        return (int) $conversacionId;
    }

    /**
     * Un bloque keyset de la exportación CSV (`ExportarTicketsCsvQuery`): tickets
     * que cumplen los filtros de la lista, creados dentro de [`$from`, `$to`]
     * (`Y-m-d H:i:s`), con id mayor que `$afterId`, por id ascendente.
     *
     * @return list<array<string, mixed>>
     */
    public function findBloqueDeExportacion(FiltrosDeTicket $filtros, string $from, string $to, int $afterId, int $limit): array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select(
                't.id', 't.status', 't.priority', 't.assigned_agent_id', 't.phone_number_id',
                't.created_at', 't.first_response_at', 't.resolved_at', 't.closed_at',
                'TIMESTAMPDIFF(SECOND, t.created_at, t.first_response_at) AS first_response_seconds',
                'TIMESTAMPDIFF(SECOND, t.created_at, t.resolved_at) AS resolution_seconds',
                'c.phone AS contact_phone', 'c.name AS contact_name',
                'a.name AS agent_name',
            )
            ->from('tickets', 't')
            ->innerJoin('t', 'conversations', 'conv', 't.conversation_id = conv.id')
            ->innerJoin('t', 'contacts', 'c', 'conv.contact_id = c.id')
            ->leftJoin('t', 'agents', 'a', 't.assigned_agent_id = a.id');

        $this->aplicarFiltrosDeLista($qb, $filtros);

        return $qb->andWhere('t.created_at >= :from')
            ->andWhere('t.created_at <= :to')
            ->andWhere('t.id > :afterId')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('afterId', $afterId)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults($limit)
            ->fetchAllAssociative();
    }

    /**
     * Por contacto, el id más alto entre sus tickets que no están cerrados
     * ni resueltos, opcionalmente limitado a un espacio de trabajo (`phone_number_id`).
     * Los contactos sin un ticket así no aparecen en el resultado.
     *
     * @param int[] $contactoIds
     * @return list<array{contact_id: mixed, ticket_id: mixed}>
     */
    public function findUltimosTicketIdsAbiertosByContacto(array $contactoIds, ?string $phoneNumberId): array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('t.contact_id', 'MAX(t.id) AS ticket_id')
            ->from('tickets', 't')
            ->where('t.contact_id IN (:contactIds)')
            ->andWhere('t.status NOT IN (:closedStatuses)')
            ->setParameter('contactIds', $contactoIds, ArrayParameterType::INTEGER)
            ->setParameter('closedStatuses', [EstadoDeTicket::CERRADO->value, EstadoDeTicket::RESUELTO->value], ArrayParameterType::STRING);

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phone_number_id = :phoneNumberId')
                ->setParameter('phoneNumberId', $phoneNumberId);
        }

        return $qb->groupBy('t.contact_id')->fetchAllAssociative();
    }

    /**
     * Agrega los filtros de la lista como cláusulas WHERE a una query sobre `tickets t`,
     * en este orden: asignación o mios, alcance de responsabilidad, espacio de trabajo, estado,
     * un EXISTS por etiqueta (semántica AND), equipo.
     */
    private function aplicarFiltrosDeLista(QueryBuilder $qb, FiltrosDeTicket $filtros): void
    {
        if ($filtros->asignacion === FiltroDeBandeja::SIN_ASIGNAR) {
            $qb->andWhere('t.assigned_agent_id IS NULL');
        } elseif ($filtros->asignacion === FiltroDeBandeja::ASIGNADOS) {
            $qb->andWhere('t.assigned_agent_id IS NOT NULL');
        } elseif ($filtros->misAgenteId !== null) {
            $qb->andWhere('t.assigned_agent_id = :mineAgentId')
                ->setParameter('mineAgentId', $filtros->misAgenteId);
        }

        if ($filtros->scopeAgenteId !== null) {
            $qb->andWhere('t.assigned_agent_id = :scopeAgentId')
                ->setParameter('scopeAgentId', $filtros->scopeAgenteId);
        }

        if ($filtros->phoneNumberId !== null) {
            $qb->andWhere('t.phone_number_id = :phoneNumberId')
                ->setParameter('phoneNumberId', $filtros->phoneNumberId);
        }

        if ($filtros->estados !== []) {
            $qb->andWhere('t.status IN (:statuses)')
                ->setParameter('statuses', $filtros->estados, ArrayParameterType::STRING);
        }

        foreach ($filtros->etiquetas as $i => $nombre) {
            $qb->andWhere("EXISTS (SELECT 1 FROM ticket_tags tt JOIN tags tg ON tg.id = tt.tag_id WHERE tt.ticket_id = t.id AND tg.name = :tag{$i})")
                ->setParameter("tag{$i}", $nombre);
        }

        if ($filtros->equipoId !== null) {
            $qb->andWhere('t.team_id = :teamId')
                ->setParameter('teamId', $filtros->equipoId);
        }
    }
}
