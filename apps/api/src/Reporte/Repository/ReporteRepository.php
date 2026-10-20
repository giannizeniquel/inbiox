<?php

namespace App\Reporte\Repository;

use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\TipoDeRemitente;
use App\Ticket\EstadoDeTicket;
use App\Ticket\Ticket;
use App\Ticket\TipoDeEventoDeTicket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositorio para las queries de reportes.
 *
 * Provee queries de métricas agregadas de tickets:
 * - Tiempo de primera respuesta (FRT)
 * - Tiempo de resolución (RT)
 * - Cantidad de tickets por estado
 * - Volumen a lo largo del tiempo
 * - Rendimiento de los agentes
 *
 * Todos los métodos admiten el parámetro opcional ?int $agenteId para el alcance por responsabilidad.
 */
class ReporteRepository extends ServiceEntityRepository
{
    /** Estados que cuentan como carga activa de un agente. */
    private const ESTADOS_ACTIVOS = [
        EstadoDeTicket::NUEVO->value,
        EstadoDeTicket::ACTIVO->value,
        EstadoDeTicket::EN_ESPERA->value,
    ];

    private bool $isPostgres = false;
    
    public function __construct(
        ManagerRegistry $registry,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($registry, Ticket::class);
        
        // Detectar la plataforma de base de datos al construir
        $this->detectarPlataforma();
    }
    
    private function detectarPlataforma(): void
    {
        try {
            $params = $this->entityManager->getConnection()->getParams();
            $url = $params['url'] ?? '';
            $this->isPostgres = str_contains($url, 'pgsql') || str_contains($url, 'postgres');
        } catch (\Throwable $e) {
            $this->isPostgres = false;
        }
    }

    /**
     * Obtiene la expresión SQL para la diferencia de tiempo entre dos columnas.
     *
     * @param string $startColumn Columna de inicio (p. ej., 't.created_at')
     * @param string $endColumn Columna de fin (p. ej., 't.first_response_at')
     * @return string Expresión SQL para la diferencia de tiempo en segundos
     */
    private function getTimeDiffExpression(string $startColumn, string $endColumn): string
    {
        if ($this->isPostgres) {
            return "EXTRACT(EPOCH FROM ({$endColumn} - {$startColumn}))";
        }
        return "TIMESTAMPDIFF(SECOND, {$startColumn}, {$endColumn})";
    }

    /**
     * Construye los filtros opcionales de ticket compartidos por las queries nativas de reportes
     * y vincula sus parámetros. Las cláusulas mantienen un orden fijo: agente,
     * espacio de trabajo, equipo. Devuelve una cadena vacía cuando no hay ningún filtro.
     *
     * @param array<string, mixed> $params Parámetros de la query, ampliados en el lugar
     * @param string $alias Alias de la tabla tickets en la query
     */
    private function clausulaDeFiltroDeTicket(array &$params, string $alias, ?int $agenteId, ?string $phoneNumberId, ?int $equipoId): string
    {
        $clause = '';

        if ($agenteId !== null) {
            $clause .= " AND {$alias}.assigned_agent_id = :agentId";
            $params['agentId'] = $agenteId;
        }

        if ($phoneNumberId !== null) {
            $clause .= " AND {$alias}.phone_number_id = :phoneNumberId";
            $params['phoneNumberId'] = $phoneNumberId;
        }

        if ($equipoId !== null) {
            $clause .= " AND {$alias}.team_id = :teamId";
            $params['teamId'] = $equipoId;
        }

        return $clause;
    }

    /**
     * Obtiene las métricas de tiempo de primera respuesta.
     *
     * Todos los valores están en SEGUNDOS (basados en UTC).
     * Usar los campos de conveniencia _minutes para la UI.
     *
     * SQL nativo porque la demora es un TIMESTAMPDIFF() entre dos columnas,
     * para el que DQL no tiene función. Probado por Sprint09AReportingTest,
     * ReportingRepositoryTeamFilterTest y ReportingControllerCharacterizationTest.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por agente asignado
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array{avg_frt_seconds: float|null, min_frt_seconds: int|null, max_frt_seconds: int|null, avg_frt_minutes: float|null, min_frt_minutes: int|null, max_frt_minutes: int|null}
     */
    public function getMetricasDeFrt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $conn = $this->entityManager->getConnection();
        
        $timeDiff = $this->getTimeDiffExpression('t.created_at', 't.first_response_at');
        
        $sql = "SELECT 
            AVG({$timeDiff}) as avg_frt,
            MIN({$timeDiff}) as min_frt,
            MAX({$timeDiff}) as max_frt
        FROM tickets t
        WHERE t.first_response_at IS NOT NULL
        AND t.created_at >= :from AND t.created_at < :to";
        
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        
        $sql .= $this->clausulaDeFiltroDeTicket($params, 't', $agenteId, $phoneNumberId, $equipoId);

        $result = $conn->fetchAssociative($sql, $params);

        $avgFrt = $result['avg_frt'] !== null ? (float) $result['avg_frt'] : null;
        $minFrt = $result['min_frt'] !== null ? (int) $result['min_frt'] : null;
        $maxFrt = $result['max_frt'] !== null ? (int) $result['max_frt'] : null;
        
        return [
            'avg_frt_seconds' => $avgFrt,
            'min_frt_seconds' => $minFrt,
            'max_frt_seconds' => $maxFrt,
            'avg_frt_minutes' => $avgFrt !== null ? round($avgFrt / 60, 2) : null,
            'min_frt_minutes' => $minFrt !== null ? (int) ($minFrt / 60) : null,
            'max_frt_minutes' => $maxFrt !== null ? (int) ($maxFrt / 60) : null,
        ];
    }

    /**
     * Obtiene las métricas de tiempo de resolución.
     *
     * Todos los valores están en SEGUNDOS (basados en UTC).
     * Usar los campos de conveniencia _minutes para la UI.
     *
     * SQL nativo porque la demora es un TIMESTAMPDIFF() entre dos columnas,
     * para el que DQL no tiene función. Probado por Sprint09AReportingTest,
     * ReportingRepositoryTeamFilterTest y ReportingControllerCharacterizationTest.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por agente asignado
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array{avg_rt_seconds: float|null, min_rt_seconds: int|null, max_rt_seconds: int|null, avg_rt_minutes: float|null, min_rt_minutes: int|null, max_rt_minutes: int|null}
     */
    public function getMetricasDeRt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $conn = $this->entityManager->getConnection();
        
        $timeDiff = $this->getTimeDiffExpression('t.created_at', 't.resolved_at');
        
        $sql = "SELECT 
            AVG({$timeDiff}) as avg_rt,
            MIN({$timeDiff}) as min_rt,
            MAX({$timeDiff}) as max_rt
        FROM tickets t
        WHERE t.resolved_at IS NOT NULL
        AND t.created_at >= :from AND t.created_at < :to";
        
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        
        $sql .= $this->clausulaDeFiltroDeTicket($params, 't', $agenteId, $phoneNumberId, $equipoId);

        $result = $conn->fetchAssociative($sql, $params);

        $avgRt = $result['avg_rt'] !== null ? (float) $result['avg_rt'] : null;
        $minRt = $result['min_rt'] !== null ? (int) $result['min_rt'] : null;
        $maxRt = $result['max_rt'] !== null ? (int) $result['max_rt'] : null;
        
        return [
            'avg_rt_seconds' => $avgRt,
            'min_rt_seconds' => $minRt,
            'max_rt_seconds' => $maxRt,
            'avg_rt_minutes' => $avgRt !== null ? round($avgRt / 60, 2) : null,
            'min_rt_minutes' => $minRt !== null ? (int) ($minRt / 60) : null,
            'max_rt_minutes' => $maxRt !== null ? (int) ($maxRt / 60) : null,
        ];
    }

    /**
     * Obtiene la cantidad de tickets agrupada por estado.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por agente asignado
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array{new: int, active: int, waiting: int, resolved: int, closed: int}
     */
    public function getConteosPorEstado(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $qb = $this->createQueryBuilder('t');

        $qb->select('t.estado AS status', 'COUNT(t.id) as count')
            ->andWhere('t.creadoAt >= :from AND t.creadoAt < :to')
            ->groupBy('t.estado')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($agenteId !== null) {
            $qb->andWhere('t.agenteAsignadoId = :agentId')
               ->setParameter('agentId', $agenteId);
        }

        if ($phoneNumberId !== null) {
            $qb->andWhere('t.phoneNumberId = :phoneNumberId')
               ->setParameter('phoneNumberId', $phoneNumberId);
        }

        if ($equipoId !== null) {
            $qb->andWhere('t.equipo = :teamId')
               ->setParameter('teamId', $equipoId);
        }

        $results = $qb->getQuery()->getResult();

        // Las claves de la respuesta son los valores del enum, en el orden de sus casos.
        $conteos = [];
        foreach (EstadoDeTicket::cases() as $estado) {
            $conteos[$estado->value] = 0;
        }

        foreach ($results as $row) {
            $estado = $row['status'];
            if (isset($conteos[$estado])) {
                $conteos[$estado] = (int) $row['count'];
            }
        }

        return $conteos;
    }

    /**
     * Obtiene el volumen de tickets agrupado por día.
     *
     * SQL nativo porque agrupa por DATE(), para la que DQL no tiene función.
     * Probado por Sprint09AReportingTest, ReportingRepositoryTeamFilterTest y
     * ReportingControllerCharacterizationTest.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por agente asignado
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array<int, array{date: string, tickets_created: int, tickets_resolved: int}>
     */
    public function getVolumenEnElTiempo(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $conn = $this->entityManager->getConnection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $filterClause = $this->clausulaDeFiltroDeTicket($params, 't', $agenteId, $phoneNumberId, $equipoId);

        // Tickets creados agrupados por fecha
        $sqlCreated = 'SELECT DATE(t.created_at) as date, COUNT(t.id) as tickets_created
        FROM tickets t
        WHERE t.created_at >= :from AND t.created_at < :to' . $filterClause . '
        GROUP BY DATE(t.created_at)';

        $createdResults = $conn->fetchAllAssociative($sqlCreated, $params);

        // Tickets resueltos agrupados por fecha
        $sqlResolved = 'SELECT DATE(t.resolved_at) as date, COUNT(t.id) as tickets_resolved
        FROM tickets t
        WHERE t.resolved_at IS NOT NULL AND t.resolved_at >= :from AND t.resolved_at < :to' . $filterClause . '
        GROUP BY DATE(t.resolved_at)';
        
        $resolvedResults = $conn->fetchAllAssociative($sqlResolved, $params);

        // Combinar los resultados
        $mapaDeVolumen = [];
        foreach ($createdResults as $row) {
            $date = $row['date'];
            $mapaDeVolumen[$date] = [
                'date' => $date,
                'tickets_created' => (int) $row['tickets_created'],
                'tickets_resolved' => 0,
            ];
        }

        foreach ($resolvedResults as $row) {
            $date = $row['date'];
            if (!isset($mapaDeVolumen[$date])) {
                $mapaDeVolumen[$date] = [
                    'date' => $date,
                    'tickets_created' => 0,
                    'tickets_resolved' => 0,
                ];
            }
            $mapaDeVolumen[$date]['tickets_resolved'] = (int) $row['tickets_resolved'];
        }

        // Ordenar por fecha
        ksort($mapaDeVolumen);

        return array_values($mapaDeVolumen);
    }

    /**
     * Obtiene las métricas de rendimiento de los agentes.
     *
     * Todos los valores de tiempo están en SEGUNDOS (basados en UTC).
     * Usar los campos de conveniencia _minutes para la UI.
     *
     * SQL nativo porque los promedios usan TIMESTAMPDIFF(), para la que DQL no tiene
     * función. Probado por Sprint09AReportingTest,
     * ReportingRepositoryTeamFilterTest y ReportingControllerCharacterizationTest.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por un agente específico
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array<int, array{agent_id: int, agent_name: string|null, tickets_assigned: int, tickets_resolved: int, current_active_tickets: int, max_active_tickets: int, current_load: float, avg_frt_seconds: float|null, avg_frt_minutes: float|null, avg_rt_seconds: float|null, avg_rt_minutes: float|null}>
     */
    public function getRendimientoDeAgentes(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $conn = $this->entityManager->getConnection();

        $frtTimeDiff = $this->getTimeDiffExpression('t.created_at', 't.first_response_at');
        $rtTimeDiff = $this->getTimeDiffExpression('t.created_at', 't.resolved_at');

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'activeStatuses' => self::ESTADOS_ACTIVOS,
        ];
        $subFilterClause = $this->clausulaDeFiltroDeTicket($params, 't2', null, $phoneNumberId, $equipoId);
        $joinFilterClause = $this->clausulaDeFiltroDeTicket($params, 't', null, $phoneNumberId, $equipoId);

        $sql = "SELECT
            a.id as agent_id,
            a.name as agent_name,
            a.max_active_tickets,
            COUNT(t.id) as tickets_assigned,
            SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as tickets_resolved,
            AVG(CASE WHEN t.first_response_at IS NOT NULL THEN {$frtTimeDiff} ELSE NULL END) as avg_frt,
            AVG(CASE WHEN t.resolved_at IS NOT NULL THEN {$rtTimeDiff} ELSE NULL END) as avg_rt,
            (SELECT COUNT(*) FROM tickets t2
             WHERE t2.assigned_agent_id = a.id
             AND t2.status IN (:activeStatuses){$subFilterClause}) as current_active_tickets
        FROM agents a
        LEFT JOIN tickets t ON t.assigned_agent_id = a.id
            AND t.created_at >= :from AND t.created_at < :to{$joinFilterClause}
        WHERE a.active = 1";

        if ($agenteId !== null) {
            $sql .= ' AND a.id = :agentId';
            $params['agentId'] = $agenteId;
        }

        $sql .= " GROUP BY a.id, a.name, a.max_active_tickets ORDER BY a.id ASC";
        
        $results = $conn->fetchAllAssociative($sql, $params, ['activeStatuses' => ArrayParameterType::STRING]);
        
        $metricasDeAgentes = [];
        foreach ($results as $row) {
            $agenteId = (int) $row['agent_id'];
            $avgFrt = $row['avg_frt'] !== null ? (float) $row['avg_frt'] : null;
            $avgRt = $row['avg_rt'] !== null ? (float) $row['avg_rt'] : null;
            $maxActivos = (int) ($row['max_active_tickets'] ?? 10);
            $activosActuales = (int) ($row['current_active_tickets'] ?? 0);
            
            $metricasDeAgentes[] = [
                'agent_id' => $agenteId,
                'agent_name' => $row['agent_name'],
                'tickets_assigned' => (int) $row['tickets_assigned'],
                'tickets_resolved' => (int) $row['tickets_resolved'],
                'current_active_tickets' => $activosActuales,
                'max_active_tickets' => $maxActivos,
                'current_load' => $maxActivos > 0 ? round($activosActuales / $maxActivos, 2) : 0.0,
                'avg_frt_seconds' => $avgFrt,
                'avg_frt_minutes' => $avgFrt !== null ? round($avgFrt / 60, 2) : null,
                'avg_rt_seconds' => $avgRt,
                'avg_rt_minutes' => $avgRt !== null ? round($avgRt / 60, 2) : null,
            ];
        }
        
        return $metricasDeAgentes;
    }

    /**
     * Obtiene, por agente, la cantidad de tickets activos frente a la capacidad máxima.
     *
     * SQL nativo conservado de la implementación original: DQL podría expresar esta
     * query, y convertirla es un cambio aparte de esta limpieza. Probado
     * por ReportingV2Test y ReportingControllerCharacterizationTest.
     *
     * @param int|null $agenteId Filtro opcional por un agente específico
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @return array<int, array{agent_id: int, agent_name: string, active_tickets: int, max_capacity: int, load_percent: float}>
     */
    public function getCargaDeAgentes(?int $agenteId = null, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();

        $params = ['activeStatuses' => self::ESTADOS_ACTIVOS];
        $workspaceClause = $this->clausulaDeFiltroDeTicket($params, 't', null, $phoneNumberId, null);

        $agentClause = '';
        if ($agenteId !== null) {
            $agentClause = ' AND a.id = :agentId';
            $params['agentId'] = $agenteId;
        }

        $sql = "SELECT
            a.id as agent_id,
            a.name as agent_name,
            a.max_active_tickets as max_capacity,
            (SELECT COUNT(*) FROM tickets t
             WHERE t.assigned_agent_id = a.id
             AND t.status IN (:activeStatuses){$workspaceClause}) as active_tickets
        FROM agents a
        WHERE a.active = 1{$agentClause}
        ORDER BY a.id ASC";

        $results = $conn->fetchAllAssociative($sql, $params, ['activeStatuses' => ArrayParameterType::STRING]);

        $metricasDeAgentes = [];
        foreach ($results as $row) {
            $capacidadMaxima = (int) $row['max_capacity'];
            $ticketsActivos = (int) $row['active_tickets'];
            $metricasDeAgentes[] = [
                'agent_id' => (int) $row['agent_id'],
                'agent_name' => $row['agent_name'],
                'active_tickets' => $ticketsActivos,
                'max_capacity' => $capacidadMaxima,
                'load_percent' => $capacidadMaxima > 0 ? round($ticketsActivos / $capacidadMaxima * 100, 1) : 0.0,
            ];
        }

        return $metricasDeAgentes;
    }

    /**
     * Obtiene los tickets cuyo assigned_agent_id IS NULL.
     *
     * SQL nativo porque `created_at` se devuelve con el formato de MySQL
     * (`Y-m-d H:i:s`); DQL lo hidrataría en un objeto DateTime. Probado por
     * ReportingV2Test y ReportingControllerCharacterizationTest.
     *
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param \DateTimeImmutable|null $from Inicio opcional del rango de fechas
     * @param \DateTimeImmutable|null $to Fin opcional del rango de fechas
     * @param int $limit Máximo de resultados (por defecto 50)
     * @return array{count: int, tickets: array<int, array{id: int, contact_name: string|null, created_at: string, priority: string}>}
     */
    public function getSinAsignar(?string $phoneNumberId = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 50): array
    {
        $conn = $this->entityManager->getConnection();

        $sql = "SELECT
            t.id,
            c.name as contact_name,
            t.created_at,
            t.priority
        FROM tickets t
        LEFT JOIN contacts c ON c.id = t.contact_id
        WHERE t.assigned_agent_id IS NULL";

        $params = [];

        $sql .= $this->clausulaDeFiltroDeTicket($params, 't', null, $phoneNumberId, null);

        if ($from !== null) {
            $sql .= ' AND t.created_at >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s');
        }

        if ($to !== null) {
            $sql .= ' AND t.created_at < :to';
            $params['to'] = $to->format('Y-m-d H:i:s');
        }

        // Contar primero
        $countSql = str_replace(
            'SELECT t.id, c.name as contact_name, t.created_at, t.priority',
            'SELECT COUNT(*) as cnt',
            $sql
        );
        $countResult = $conn->fetchAssociative($countSql, $params);
        $count = (int) ($countResult['cnt'] ?? 0);

        $sql .= ' ORDER BY t.created_at DESC LIMIT :limit';
        $params['limit'] = $limit;
        $results = $conn->fetchAllAssociative($sql, $params, ['limit' => ParameterType::INTEGER]);

        $tickets = [];
        foreach ($results as $row) {
            $tickets[] = [
                'id' => (int) $row['id'],
                'contact_name' => $row['contact_name'],
                'created_at' => $row['created_at'],
                'priority' => $row['priority'],
            ];
        }

        return [
            'count' => $count,
            'tickets' => $tickets,
        ];
    }

    /**
     * Obtiene el FRT agregado por día dentro de un rango de fechas.
     *
     * SQL nativo porque usa TIMESTAMPDIFF() y agrupa por DATE(), funciones que
     * DQL no tiene. Probado por ReportingV2Test y
     * ReportingControllerCharacterizationTest.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @return array<int, array{date: string, avg_frt_seconds: float, ticket_count: int}>
     */
    public function getTendenciaDeFrt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();

        $timeDiff = $this->getTimeDiffExpression('t.created_at', 't.first_response_at');

        $sql = "SELECT
            DATE(t.created_at) as date,
            AVG({$timeDiff}) as avg_frt,
            COUNT(t.id) as ticket_count
        FROM tickets t
        WHERE t.first_response_at IS NOT NULL
        AND t.created_at >= :from AND t.created_at < :to";

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        $sql .= $this->clausulaDeFiltroDeTicket($params, 't', null, $phoneNumberId, null);

        $sql .= ' GROUP BY DATE(t.created_at) ORDER BY date ASC';

        $results = $conn->fetchAllAssociative($sql, $params);

        $tendencia = [];
        foreach ($results as $row) {
            $tendencia[] = [
                'date' => $row['date'],
                'avg_frt_seconds' => $row['avg_frt'] !== null ? round((float) $row['avg_frt'], 1) : 0.0,
                'ticket_count' => (int) $row['ticket_count'],
            ];
        }

        return $tendencia;
    }

    /**
     * Obtiene el volumen de tickets agrupado por día de la semana x hora para el mapa de calor.
     *
     * dayOfWeek se normaliza a ISO: 1=lunes, 7=domingo.
     * DAYOFWEEK de MySQL: 1=domingo, 7=sábado → se convierte con (dow+5)%7+1
     *
     * SQL nativo porque agrupa por DAYOFWEEK() y HOUR(), funciones que DQL
     * no tiene. Probado por ReportingV2Test y
     * ReportingControllerCharacterizationTest.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @return array<int, array{day_of_week: int, hour: int, count: int}>
     */
    public function getMapaDeCalor(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $conn = $this->entityManager->getConnection();

        if ($this->isPostgres) {
            // PostgreSQL: EXTRACT(DOW) → 0=domingo, 6=sábado
            // Normalizar a ISO: (dow+6)%7+1 → 1=lunes, 7=domingo
            $dowExpr = "((EXTRACT(DOW FROM t.created_at) + 6) % 7 + 1)";
            $hourExpr = "EXTRACT(HOUR FROM t.created_at)";
        } else {
            // MySQL: DAYOFWEEK → 1=domingo, 7=sábado
            // Normalizar a ISO: (dow+5)%7+1 → 1=lunes, 7=domingo
            $dowExpr = "((DAYOFWEEK(t.created_at) + 5) % 7 + 1)";
            $hourExpr = "HOUR(t.created_at)";
        }

        $sql = "SELECT
            {$dowExpr} as day_of_week,
            {$hourExpr} as hour,
            COUNT(t.id) as count
        FROM tickets t
        WHERE t.created_at >= :from AND t.created_at < :to";

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        $sql .= $this->clausulaDeFiltroDeTicket($params, 't', null, $phoneNumberId, null);

        $sql .= ' GROUP BY day_of_week, hour ORDER BY day_of_week ASC, hour ASC';

        $results = $conn->fetchAllAssociative($sql, $params);

        $mapaDeCalor = [];
        foreach ($results as $row) {
            $count = (int) $row['count'];
            if ($count > 0) {
                $mapaDeCalor[] = [
                    'day_of_week' => (int) $row['day_of_week'],
                    'hour' => (int) $row['hour'],
                    'count' => $count,
                ];
            }
        }

        return $mapaDeCalor;
    }

    /**
     * Obtiene los contactos con >= umbral tickets en los últimos N días.
     *
     * SQL nativo conservado de la implementación original: DQL podría expresar esta
     * query, y convertirla es un cambio aparte de esta limpieza. Probado
     * por ReportingV2Test y ReportingControllerCharacterizationTest.
     *
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int $umbral Cantidad mínima de tickets (por defecto 3)
     * @param int $days Ventana retrospectiva en días (por defecto 90)
     * @return array<int, array{contact_id: int, contact_name: string|null, email: string|null, ticket_count: int, last_ticket_at: string}>
     */
    public function getContactosRecurrentes(?string $phoneNumberId = null, int $umbral = 3, int $days = 90): array
    {
        $conn = $this->entityManager->getConnection();

        $since = (new \DateTimeImmutable())->modify("-{$days} days");

        $sql = "SELECT
            c.id as contact_id,
            c.name as contact_name,
            c.email as email,
            COUNT(t.id) as ticket_count,
            MAX(t.created_at) as last_ticket_at
        FROM contacts c
        INNER JOIN tickets t ON t.contact_id = c.id
        WHERE t.created_at >= :since";

        $params = ['since' => $since->format('Y-m-d H:i:s')];

        $sql .= $this->clausulaDeFiltroDeTicket($params, 't', null, $phoneNumberId, null);

        $sql .= ' GROUP BY c.id, c.name, c.email
                  HAVING COUNT(t.id) >= :threshold
                  ORDER BY ticket_count DESC';

        $params['threshold'] = $umbral;

        $results = $conn->fetchAllAssociative($sql, $params);

        $recurrentes = [];
        foreach ($results as $row) {
            $recurrentes[] = [
                'contact_id' => (int) $row['contact_id'],
                'contact_name' => $row['contact_name'],
                'email' => $row['email'],
                'ticket_count' => (int) $row['ticket_count'],
                'last_ticket_at' => $row['last_ticket_at'],
            ];
        }

        return $recurrentes;
    }

    /**
     * Obtiene los tickets que salieron del estado resuelto (eventos reapertura_automatica).
     *
     * SQL nativo porque `resolved_at` y `reopened_at` se devuelven con el formato
     * de MySQL (`Y-m-d H:i:s`); DQL los hidrataría en objetos DateTime.
     * Probado por ReportingV2Test y ReportingControllerCharacterizationTest.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int $limit Máximo de resultados (por defecto 100)
     * @return array<int, array{ticket_id: int, contact_name: string|null, resolved_at: string|null, reopened_at: string, current_status: string}>
     */
    public function getReabiertos(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null, int $limit = 100): array
    {
        $conn = $this->entityManager->getConnection();

        $sql = "SELECT
            t.id as ticket_id,
            c.name as contact_name,
            t.resolved_at,
            te.created_at as reopened_at,
            t.status as current_status
        FROM ticket_events te
        INNER JOIN tickets t ON t.id = te.ticket_id
        LEFT JOIN contacts c ON c.id = t.contact_id
        WHERE te.type = :tipo_reapertura
        AND te.created_at >= :from AND te.created_at < :to";

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'tipo_reapertura' => TipoDeEventoDeTicket::REAPERTURA_AUTOMATICA,
        ];

        $sql .= $this->clausulaDeFiltroDeTicket($params, 't', null, $phoneNumberId, null);

        $sql .= ' ORDER BY te.created_at DESC LIMIT :limit';
        $params['limit'] = $limit;

        $results = $conn->fetchAllAssociative($sql, $params, ['limit' => ParameterType::INTEGER]);

        $reabiertos = [];
        foreach ($results as $row) {
            $reabiertos[] = [
                'ticket_id' => (int) $row['ticket_id'],
                'contact_name' => $row['contact_name'],
                'resolved_at' => $row['resolved_at'],
                'reopened_at' => $row['reopened_at'],
                'current_status' => $row['current_status'],
            ];
        }

        return $reabiertos;
    }

    /**
     * Obtiene el corte de atribución: el `created_at` más antiguo entre los mensajes
     * con `sender_type IN ('agent', 'automatic', 'contact')`.
     *
     * Los mensajes anteriores a este timestamp tienen `sender_type IS NULL` (filas legacy,
     * previas al despliegue de la atribución) y deben excluirse del muestreo de demora
     * de respuesta y de la atribución en todas partes. Devuelve null solo cuando toda la
     * tabla `messages` está sin atribuir (no existe ninguna fila con sender_type no NULL).
     *
     * SQL nativo porque combina tres subqueries `LIMIT 1` con UNION
     * ALL, que DQL no puede expresar. Probado por ReportingResponseDelayTest.
     */
    public function getCorteDeAtribucion(): ?\DateTimeImmutable
    {
        $conn = $this->entityManager->getConnection();

        $sql = "SELECT MIN(c) AS data_available_from FROM (
                (SELECT created_at AS c FROM messages WHERE sender_type = :senderAgent     ORDER BY created_at LIMIT 1)
              UNION ALL
                (SELECT created_at AS c FROM messages WHERE sender_type = :senderAutomatic ORDER BY created_at LIMIT 1)
              UNION ALL
                (SELECT created_at AS c FROM messages WHERE sender_type = :senderContact   ORDER BY created_at LIMIT 1)
            ) probe";

        $result = $conn->fetchAssociative($sql, [
            'senderAgent' => TipoDeRemitente::AGENTE->value,
            'senderAutomatic' => TipoDeRemitente::AUTOMATICO->value,
            'senderContact' => TipoDeRemitente::CONTACTO->value,
        ]);
        $value = $result['data_available_from'] ?? null;

        return $value !== null ? new \DateTimeImmutable($value) : null;
    }

    /**
     * Obtiene las métricas de demora de respuesta agregadas y por agente.
     *
     * Alcance por partición de episodios: un mensaje pertenece al ticket abierto más
     * recientemente en el mismo momento o antes que él en la misma conversación (`[ticket.created_at,
     * next_ticket.created_at)`). Colapso de ráfagas: los mensajes entrantes consecutivos
     * sin una respuesta `sender_type='agent'` intermedia forman una corrida: exactamente
     * una muestra de demora por corrida, medida desde el primer mensaje de la corrida hasta la
     * siguiente respuesta de agente. Los mensajes `automatic` nunca dividen una ráfaga ni detienen el
     * reloj. Las filas legacy `sender_type IS NULL` (anteriores al corte de
     * atribución) se excluyen de todo el flujo. El filtro de rango es sobre
     * `tickets.created_at` (inicio del episodio), no sobre el timestamp de la respuesta.
     *
     * SQL nativo porque el emparejamiento de ráfagas necesita CTE y funciones de ventana
     * (`LEAD() OVER`, `SUM() OVER`, `ROW_NUMBER() OVER`, `COUNT() OVER`), que
     * ni DQL ni el QueryBuilder de DBAL pueden expresar. Los parámetros van vinculados.
     * Probado por ReportingResponseDelayTest y ReportingControllerCharacterizationTest.
     *
     * @return array{aggregate: array{avg_delay_seconds: float|null, sample_count: int, unanswered_inbound_count: int}, per_agent: array<int, array{agent_id: int, agent_name: string|null, avg_delay_seconds: float|null, sample_count: int}>}
     */
    public function getMetricasDeDemoraDeRespuesta(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $rows = $this->fetchFilasDeDemoraDeRespuesta($from, $to, $phoneNumberId);

        return $this->agregarFilasDeDemoraDeRespuesta($rows);
    }

    /**
     * Una fila por cada agente que respondió (y una con agente null para las ráfagas
     * que nadie respondió), con su cantidad de muestras, su suma de demoras y su cantidad sin responder.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchFilasDeDemoraDeRespuesta(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId): array
    {
        $conn = $this->entityManager->getConnection();
        $timeDiff = $this->getTimeDiffExpression('b.inbound_at', 'r.reply_at');

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'senderAgent' => TipoDeRemitente::AGENTE->value,
            'senderAutomatic' => TipoDeRemitente::AUTOMATICO->value,
            'senderContact' => TipoDeRemitente::CONTACTO->value,
            'inbound' => DireccionDeMensaje::ENTRANTE->value,
        ];

        $workspaceClause = $this->clausulaDeFiltroDeTicket($params, 't', null, $phoneNumberId, null);

        $sql = "WITH cutoff AS (
                SELECT MIN(c) AS data_available_from FROM (
                    (SELECT created_at AS c FROM messages WHERE sender_type = :senderAgent     ORDER BY created_at LIMIT 1)
                  UNION ALL
                    (SELECT created_at AS c FROM messages WHERE sender_type = :senderAutomatic ORDER BY created_at LIMIT 1)
                  UNION ALL
                    (SELECT created_at AS c FROM messages WHERE sender_type = :senderContact   ORDER BY created_at LIMIT 1)
                ) probe
            ),
            scoped_convs AS (
                SELECT DISTINCT t.conversation_id FROM tickets t
                WHERE t.conversation_id IS NOT NULL
                  AND t.created_at >= :from AND t.created_at < :to{$workspaceClause}
            ),
            episodes AS (
                SELECT t.id AS ticket_id, t.conversation_id, t.created_at AS episode_start,
                       LEAD(t.created_at) OVER (PARTITION BY t.conversation_id
                                                ORDER BY t.created_at, t.id) AS episode_end
                FROM tickets t
                JOIN scoped_convs sc ON sc.conversation_id = t.conversation_id
            ),
            msgs AS (
                SELECT e.ticket_id, m.id, m.created_at, m.direction, m.sender_type, m.sender_agent_id
                FROM episodes e
                JOIN messages m ON m.conversation_id = e.conversation_id
                               AND m.created_at >= e.episode_start
                               AND (e.episode_end IS NULL OR m.created_at < e.episode_end)
                CROSS JOIN cutoff cf
                WHERE e.episode_start >= :from AND e.episode_start < :to
                  AND m.sender_type IS NOT NULL
                  AND m.created_at >= cf.data_available_from
            ),
            seq AS (
                SELECT s.*,
                       SUM(CASE WHEN s.sender_type = :senderAgent THEN 1 ELSE 0 END)
                         OVER (PARTITION BY s.ticket_id ORDER BY s.created_at, s.id
                               ROWS UNBOUNDED PRECEDING) AS reply_seq
                FROM msgs s
            ),
            bursts AS (
                SELECT ticket_id, reply_seq, created_at AS inbound_at, burst_size
                FROM (
                    SELECT q.ticket_id, q.reply_seq, q.created_at, q.id,
                           ROW_NUMBER() OVER (PARTITION BY q.ticket_id, q.reply_seq
                                              ORDER BY q.created_at, q.id) AS rn,
                           COUNT(*) OVER (PARTITION BY q.ticket_id, q.reply_seq) AS burst_size
                    FROM seq q WHERE q.direction = :inbound
                ) ranked
                WHERE rn = 1
            ),
            agent_replies AS (
                SELECT ticket_id, reply_seq, created_at AS reply_at, sender_agent_id
                FROM seq WHERE sender_type = :senderAgent
            )
            SELECT r.sender_agent_id AS agent_id, a.name AS agent_name,
                   COUNT(r.reply_at) AS sample_count,
                   SUM({$timeDiff}) AS sum_delay,
                   SUM(CASE WHEN r.reply_at IS NULL THEN 1 ELSE 0 END) AS unanswered_count
            FROM bursts b
            LEFT JOIN agent_replies r ON r.ticket_id = b.ticket_id
                                     AND r.reply_seq = b.reply_seq + 1
            LEFT JOIN agents a ON a.id = r.sender_agent_id
            GROUP BY r.sender_agent_id, a.name
            ORDER BY r.sender_agent_id";

        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * Combina las filas por agente en el agregado y en la lista por agente.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{aggregate: array{avg_delay_seconds: float|null, sample_count: int, unanswered_inbound_count: int}, per_agent: array<int, array{agent_id: int, agent_name: string|null, avg_delay_seconds: float|null, sample_count: int}>}
     */
    private function agregarFilasDeDemoraDeRespuesta(array $rows): array
    {
        $aggregateSampleCount = 0;
        $sumaDeDemoraAgregada = 0.0;
        $cantidadDeEntrantesSinResponder = 0;
        $porAgente = [];

        foreach ($rows as $row) {
            $sampleCount = (int) $row['sample_count'];
            $sumaDeDemora = $row['sum_delay'] !== null ? (float) $row['sum_delay'] : null;
            $cantidadDeEntrantesSinResponder += (int) $row['unanswered_count'];

            if ($sampleCount > 0) {
                $aggregateSampleCount += $sampleCount;
                $sumaDeDemoraAgregada += $sumaDeDemora ?? 0.0;
            }

            if ($row['agent_id'] !== null) {
                $porAgente[] = [
                    'agent_id' => (int) $row['agent_id'],
                    'agent_name' => $row['agent_name'],
                    'avg_delay_seconds' => $sampleCount > 0 ? round($sumaDeDemora / $sampleCount, 2) : null,
                    'sample_count' => $sampleCount,
                ];
            }
        }

        return [
            'aggregate' => [
                'avg_delay_seconds' => $aggregateSampleCount > 0 ? round($sumaDeDemoraAgregada / $aggregateSampleCount, 2) : null,
                'sample_count' => $aggregateSampleCount,
                'unanswered_inbound_count' => $cantidadDeEntrantesSinResponder,
            ],
            'per_agent' => $porAgente,
        ];
    }

    /**
     * Obtiene el detalle de participación por ticket: participantes, muestras de demora
     * por agente, actividad automática y pares de ráfagas, acotados al
     * propio episodio del ticket. Reutiliza el mismo muestreo basado en ráfagas y las
     * reglas de inicio y detención del reloj que {@see getMetricasDeDemoraDeRespuesta()}.
     *
     * SQL nativo porque el emparejamiento de ráfagas necesita CTE y funciones de ventana
     * (`SUM() OVER`, `ROW_NUMBER() OVER`, `COUNT() OVER`), que ni DQL
     * ni el QueryBuilder de DBAL pueden expresar. Los parámetros van vinculados. Probado
     * directamente por los casos `testGetTicketParticipation*` y de empate degenerado
     * de `tests/Integration/ReportingResponseDelayTest.php`.
     *
     * @return array{ticket_id: int, data_available_from: \DateTimeImmutable|null, partial_data: bool, participants: array<int, array{agent_id: int, agent_name: string|null, message_count: int, sample_count: int, avg_delay_seconds: float|null}>, automatic_message_count: int, unanswered_inbound_count: int, pairs: array<int, array{run_start_at: string, reply_at: string|null, agent_id: int|null, delay_seconds: float|null}>}|null null cuando el ticket no existe o no tiene conversación
     */
    public function getParticipacionEnTicket(int $ticketId): ?array
    {
        $episodio = $this->findEpisodioDeParticipacion($ticketId);
        if ($episodio === null) {
            return null;
        }

        $corte = $this->getCorteDeAtribucion();
        $filasDePares = $this->fetchFilasDeParesDeParticipacion($episodio, $corte);
        $cantidadesDeMensajes = $this->countMensajesDelEpisodioPorRemitente($episodio);
        [$pairs, $estadisticasDeParticipantes, $cantidadDeEntrantesSinResponder] = $this->plegarParesDeParticipacion($filasDePares);
        $participantes = $this->armarParticipantes($cantidadesDeMensajes['by_agent'], $estadisticasDeParticipantes);

        return [
            'ticket_id' => $ticketId,
            'data_available_from' => $corte,
            'partial_data' => $cantidadesDeMensajes['unattributed'] > 0 || $corte === null,
            'participants' => $participantes,
            'automatic_message_count' => $cantidadesDeMensajes['automatic'],
            'unanswered_inbound_count' => $cantidadDeEntrantesSinResponder,
            'pairs' => $pairs,
        ];
    }

    /**
     * El episodio del ticket: su conversación, su propia hora de creación como
     * inicio y la hora de creación del siguiente ticket de esa conversación como
     * fin (null para el ticket más reciente).
     *
     * @return array{conversation_id: int, start: string, end: string|null}|null null cuando el ticket no existe o no tiene conversación
     */
    private function findEpisodioDeParticipacion(int $ticketId): ?array
    {
        $conn = $this->entityManager->getConnection();

        $ticketRow = $conn->fetchAssociative(
            'SELECT id, conversation_id, created_at FROM tickets WHERE id = :ticketId',
            ['ticketId' => $ticketId]
        );

        if ($ticketRow === false || $ticketRow['conversation_id'] === null) {
            return null;
        }

        $conversacionId = (int) $ticketRow['conversation_id'];
        $inicioDeEpisodio = $ticketRow['created_at'];

        // Fin del episodio: siguiente ticket de la misma conversación, con desempate
        // lexicográfico por (created_at, id), el mismo orden que la versión con LEAD(), para que ambas
        // queries coincidan en los límites del episodio (ver la nota "degenerate tie" de design.md).
        $episodeEndRow = $conn->fetchAssociative(
            'SELECT MIN(t2.created_at) AS episode_end FROM tickets t2
             WHERE t2.conversation_id = :conversationId
               AND (t2.created_at > :episodeStart
                    OR (t2.created_at = :episodeStart AND t2.id > :ticketId))',
            ['conversationId' => $conversacionId, 'episodeStart' => $inicioDeEpisodio, 'ticketId' => $ticketId]
        );

        return [
            'conversation_id' => $conversacionId,
            'start' => $inicioDeEpisodio,
            'end' => $episodeEndRow['episode_end'] ?? null,
        ];
    }

    /**
     * Una fila por cada ráfaga entrante del episodio (solo mensajes atribuidos posteriores
     * al corte), unida a la respuesta de agente que la cerró, si la hay.
     *
     * @param array{conversation_id: int, start: string, end: string|null} $episodio
     * @return list<array<string, mixed>>
     */
    private function fetchFilasDeParesDeParticipacion(array $episodio, ?\DateTimeImmutable $corte): array
    {
        $conn = $this->entityManager->getConnection();
        $timeDiff = $this->getTimeDiffExpression('b.inbound_at', 'r.reply_at');

        $params = [
            'conversationId' => $episodio['conversation_id'],
            'episodeStart' => $episodio['start'],
            'cutoff' => $corte !== null ? $corte->format('Y-m-d H:i:s') : '1970-01-01 00:00:00',
            'senderAgent' => TipoDeRemitente::AGENTE->value,
            'inbound' => DireccionDeMensaje::ENTRANTE->value,
        ];
        $episodeEndClause = '';
        if ($episodio['end'] !== null) {
            $episodeEndClause = ' AND m.created_at < :episodeEnd';
            $params['episodeEnd'] = $episodio['end'];
        }

        $sql = "WITH msgs AS (
                SELECT m.id, m.created_at, m.direction, m.sender_type, m.sender_agent_id
                FROM messages m
                WHERE m.conversation_id = :conversationId
                  AND m.created_at >= :episodeStart{$episodeEndClause}
                  AND m.sender_type IS NOT NULL
                  AND m.created_at >= :cutoff
            ),
            seq AS (
                SELECT s.*,
                       SUM(CASE WHEN s.sender_type = :senderAgent THEN 1 ELSE 0 END)
                         OVER (ORDER BY s.created_at, s.id ROWS UNBOUNDED PRECEDING) AS reply_seq
                FROM msgs s
            ),
            bursts AS (
                SELECT reply_seq, created_at AS inbound_at, burst_size
                FROM (
                    SELECT q.reply_seq, q.created_at, q.id,
                           ROW_NUMBER() OVER (PARTITION BY q.reply_seq ORDER BY q.created_at, q.id) AS rn,
                           COUNT(*) OVER (PARTITION BY q.reply_seq) AS burst_size
                    FROM seq q WHERE q.direction = :inbound
                ) ranked
                WHERE rn = 1
            ),
            agent_replies AS (
                SELECT reply_seq, created_at AS reply_at, sender_agent_id
                FROM seq WHERE sender_type = :senderAgent
            )
            SELECT b.inbound_at, b.burst_size, r.reply_at, r.sender_agent_id AS agent_id, a.name AS agent_name,
                   {$timeDiff} AS delay_seconds
            FROM bursts b
            LEFT JOIN agent_replies r ON r.reply_seq = b.reply_seq + 1
            LEFT JOIN agents a ON a.id = r.sender_agent_id
            ORDER BY b.inbound_at";

        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * Agregado sobre TODOS los mensajes del episodio (incluidas las filas legacy sender_type IS
     * NULL) para los conteos de mensajes, la actividad automática y la detección de mensajes
     * sin atribuir, de forma independiente del pipeline seq/ráfagas filtrado por el corte.
     *
     * @param array{conversation_id: int, start: string, end: string|null} $episodio
     * @return array{by_agent: array<int, int>, automatic: int, unattributed: int}
     */
    private function countMensajesDelEpisodioPorRemitente(array $episodio): array
    {
        $conn = $this->entityManager->getConnection();

        $rollupParams = ['conversationId' => $episodio['conversation_id'], 'episodeStart' => $episodio['start']];
        $rollupEpisodeEndClause = '';
        if ($episodio['end'] !== null) {
            $rollupEpisodeEndClause = ' AND m.created_at < :episodeEnd';
            $rollupParams['episodeEnd'] = $episodio['end'];
        }
        $rollupSql = "SELECT m.sender_type, m.sender_agent_id, COUNT(*) AS message_count
            FROM messages m
            WHERE m.conversation_id = :conversationId
              AND m.created_at >= :episodeStart{$rollupEpisodeEndClause}
            GROUP BY m.sender_type, m.sender_agent_id";
        $rollupRows = $conn->fetchAllAssociative($rollupSql, $rollupParams);

        $mensajesPorAgente = [];
        $cantidadDeMensajesAutomaticos = 0;
        $cantidadSinAtribuir = 0;
        foreach ($rollupRows as $row) {
            $count = (int) $row['message_count'];
            if ($row['sender_type'] === null) {
                $cantidadSinAtribuir += $count;
            } elseif ($row['sender_type'] === TipoDeRemitente::AUTOMATICO->value) {
                $cantidadDeMensajesAutomaticos += $count;
            } elseif ($row['sender_type'] === TipoDeRemitente::AGENTE->value && $row['sender_agent_id'] !== null) {
                $agenteId = (int) $row['sender_agent_id'];
                $mensajesPorAgente[$agenteId] = ($mensajesPorAgente[$agenteId] ?? 0) + $count;
            }
        }

        return [
            'by_agent' => $mensajesPorAgente,
            'automatic' => $cantidadDeMensajesAutomaticos,
            'unattributed' => $cantidadSinAtribuir,
        ];
    }

    /**
     * Convierte las filas de ráfagas en los pares públicos, los totales de demora por agente y
     * la cantidad de ráfagas que nadie respondió.
     *
     * @param list<array<string, mixed>> $filasDePares
     * @return array{0: list<array{run_start_at: string, reply_at: string|null, agent_id: int|null, delay_seconds: float|null}>, 1: array<int, array{agent_name: string|null, sample_count: int, sum_delay: float}>, 2: int}
     */
    private function plegarParesDeParticipacion(array $filasDePares): array
    {
        $estadisticasDeParticipantes = [];
        $cantidadDeEntrantesSinResponder = 0;
        $pairs = [];

        foreach ($filasDePares as $row) {
            $agenteId = $row['agent_id'] !== null ? (int) $row['agent_id'] : null;
            $segundosDeDemora = $row['delay_seconds'] !== null ? (float) $row['delay_seconds'] : null;

            $pairs[] = [
                'run_start_at' => $row['inbound_at'],
                'reply_at' => $row['reply_at'],
                'agent_id' => $agenteId,
                'delay_seconds' => $segundosDeDemora,
            ];

            if ($agenteId === null) {
                $cantidadDeEntrantesSinResponder++;
                continue;
            }

            if (!isset($estadisticasDeParticipantes[$agenteId])) {
                $estadisticasDeParticipantes[$agenteId] = [
                    'agent_name' => $row['agent_name'],
                    'sample_count' => 0,
                    'sum_delay' => 0.0,
                ];
            }
            $estadisticasDeParticipantes[$agenteId]['sample_count']++;
            $estadisticasDeParticipantes[$agenteId]['sum_delay'] += $segundosDeDemora ?? 0.0;
        }

        return [$pairs, $estadisticasDeParticipantes, $cantidadDeEntrantesSinResponder];
    }

    /**
     * Una entrada por cada agente que escribió en el episodio o respondió una ráfaga,
     * ordenadas por id de agente.
     *
     * @param array<int, int> $mensajesPorAgente
     * @param array<int, array{agent_name: string|null, sample_count: int, sum_delay: float}> $estadisticasDeParticipantes
     * @return list<array{agent_id: int, agent_name: string|null, message_count: int, sample_count: int, avg_delay_seconds: float|null}>
     */
    private function armarParticipantes(array $mensajesPorAgente, array $estadisticasDeParticipantes): array
    {
        $conn = $this->entityManager->getConnection();

        // Búsqueda de respaldo del nombre del agente: un agente que respondió sin una
        // ráfaga previa sin responder (mensaje no solicitado) tiene message_count
        // pero nunca aparece en $participantStats.
        $agenteIdsFaltantes = array_diff(array_keys($mensajesPorAgente), array_keys($estadisticasDeParticipantes));
        $nombresDeAgentes = [];
        if (!empty($agenteIdsFaltantes)) {
            $nameRows = $conn->fetchAllAssociative(
                'SELECT id, name FROM agents WHERE id IN (?)',
                [array_values(array_map('intval', $agenteIdsFaltantes))],
                [ArrayParameterType::INTEGER]
            );
            foreach ($nameRows as $nameRow) {
                $nombresDeAgentes[(int) $nameRow['id']] = $nameRow['name'];
            }
        }

        $agenteIds = array_unique(array_merge(array_keys($mensajesPorAgente), array_keys($estadisticasDeParticipantes)));
        sort($agenteIds);
        $participantes = [];
        foreach ($agenteIds as $agenteId) {
            $estadisticas = $estadisticasDeParticipantes[$agenteId] ?? ['agent_name' => null, 'sample_count' => 0, 'sum_delay' => 0.0];
            $participantes[] = [
                'agent_id' => $agenteId,
                'agent_name' => $estadisticas['agent_name'] ?? $nombresDeAgentes[$agenteId] ?? null,
                'message_count' => $mensajesPorAgente[$agenteId] ?? 0,
                'sample_count' => $estadisticas['sample_count'],
                'avg_delay_seconds' => $estadisticas['sample_count'] > 0 ? round($estadisticas['sum_delay'] / $estadisticas['sample_count'], 2) : null,
            ];
        }

        return $participantes;
    }

    /**
     * Vacía las tablas que `app:seed-reports` vuelve a llenar: ticket_events,
     * tickets, agent_shifts, contacts y agents, en ese orden, con la verificación de claves
     * foráneas desactivada mientras se ejecuta. Solo para la base de datos de prueba; el comando lo protege.
     *
     * SQL nativo porque ni DQL ni el QueryBuilder tienen TRUNCATE ni
     * variables de sesión (`SET FOREIGN_KEY_CHECKS`). TRUNCATE hace commit
     * implícito en MySQL, así que nunca debe llamarse dentro de una transacción.
     */
    public function truncateSeedTables(): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE ticket_events');
        $conn->executeStatement('TRUNCATE TABLE tickets');
        $conn->executeStatement('TRUNCATE TABLE agent_shifts');
        $conn->executeStatement('TRUNCATE TABLE contacts');
        $conn->executeStatement('TRUNCATE TABLE agents');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
