<?php

declare(strict_types=1);

namespace App\Reporte\Controller;

use App\Reporte\Query\ExportarTicketsCsvQuery;
use App\Ticket\FiltroDeBandeja;
use App\Ticket\Query\FiltrosDeTicketBuilder;
use App\Compartido\Security\AlcanceDeResponsabilidad;
use App\Compartido\Security\AlcanceDeEspacioDeTrabajo;
use App\Reporte\Query\ListAgentesDisponiblesQuery;
use App\Reporte\Query\ReporteService;
use League\Csv\Bom;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controlador REST para los endpoints de reportes y métricas.
 *
 * Todos los endpoints requieren los parámetros de query `desde` y `hasta` en UTC.
 *
 * Autorización: cualquier agente autenticado (ROLE_AGENTE o superior) puede acceder
 * a todos los endpoints de aquí. Los reportes y métricas NO se restringen por agente: esta
 * es una decisión de producto intencional (ver docs/04_sprints/sprint_16
 * (Production Readiness).md), no un bug. `getAgenteIdDeAlcance()`, más abajo, siempre
 * devuelve null (ver AlcanceDeResponsabilidad), así que no se aplica filtrado por agente
 * a ninguna query.
 */
#[IsGranted('ROLE_AGENTE')]
class ReporteController
{
    private const DATETIME_FORMAT = 'Y-m-d\TH:i:sZ';

    public function __construct(
        private ReporteService $reporteService,
        private ListAgentesDisponiblesQuery $listarAgentesDisponibles,
        private AlcanceDeResponsabilidad $alcanceDeResponsabilidad,
        private AlcanceDeEspacioDeTrabajo $alcanceDeEspacioDeTrabajo,
        private ExportarTicketsCsvQuery $exportarTicketsCsvQuery,
        private FiltrosDeTicketBuilder $filtrosDeTicketBuilder,
    ) {}

    /**
     * Interpreta la fecha y hora de un parámetro de query.
     *
     * @throws \InvalidArgumentException Si la fecha y hora es inválida o falta
     */
    private function parseDateTime(?string $value): \DateTimeImmutable
    {
        if ($value === null) {
            throw new \InvalidArgumentException('Missing required parameter: desde or hasta');
        }
        $date = \DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value);
        if ($date === false) {
            throw new \InvalidArgumentException(sprintf('Invalid datetime format: %s. Expected: %s', $value, self::DATETIME_FORMAT));
        }
        return $date;
    }

    /**
     * Obtiene el ID de agente de alcance para el filtrado por responsabilidad.
     *
     * Hoy siempre es null para todos los roles (incluido AGENT); ver
     * AlcanceDeResponsabilidad para saber por qué es intencional y no un bug.
     */
    private function getAgenteIdDeAlcance(): ?int
    {
        return $this->alcanceDeResponsabilidad->getAgenteId();
    }

    /**
     * Obtiene el phoneNumberId de alcance para el filtrado por espacio de trabajo.
     * AGENT ve solo su espacio de trabajo activo; SUPERVISOR/ADMIN ven todos.
     */
    private function getPhoneNumberIdDeAlcance(): ?string
    {
        return $this->alcanceDeEspacioDeTrabajo->getPhoneNumberIdActivo();
    }

    /**
     * Lee el parámetro de query opcional `equipoId` (filtro de equipo de selección única).
     */
    private function getEquipoId(Request $request): ?int
    {
        $equipoId = $request->query->get('equipoId');

        return null !== $equipoId ? (int) $equipoId : null;
    }

    /**
     * Obtiene las métricas de tiempo de primera respuesta.
     *
     * GET /api/reportes/frt?desde=2026-01-01T00:00:00Z&hasta=2026-01-02T00:00:00Z[&agenteId=1]
     */
    #[Route('/api/reportes/frt', name: 'app_report_frt', methods: ['GET'])]
    public function frt(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));
            $agenteId = $request->query->get('agenteId') ? (int) $request->query->get('agenteId') : null;

            // Alcance por responsabilidad: forzar agenteId para el rol AGENT
            $agenteIdDeAlcance = $this->getAgenteIdDeAlcance();
            if (null !== $agenteIdDeAlcance) {
                $agenteId = $agenteIdDeAlcance;
            }

            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
            $equipoId = $this->getEquipoId($request);

            $metrics = $this->reporteService->getFrt($from, $to, $agenteId, $phoneNumberId, $equipoId);
            return new JsonResponse($metrics);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene las métricas de tiempo de resolución.
     *
     * GET /api/reportes/rt?desde=2026-01-01T00:00:00Z&hasta=2026-01-02T00:00:00Z
     */
    #[Route('/api/reportes/rt', name: 'app_report_rt', methods: ['GET'])]
    public function rt(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));

            $agenteIdDeAlcance = $this->getAgenteIdDeAlcance();
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
            $equipoId = $this->getEquipoId($request);
            $metrics = $this->reporteService->getRt($from, $to, $agenteIdDeAlcance, $phoneNumberId, $equipoId);

            return new JsonResponse($metrics);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene la cantidad de tickets por estado.
     *
     * GET /api/reportes/conteos?desde=2026-01-01T00:00:00Z&hasta=2026-01-02T00:00:00Z
     */
    #[Route('/api/reportes/conteos', name: 'app_report_counts', methods: ['GET'])]
    public function conteos(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));

            $agenteIdDeAlcance = $this->getAgenteIdDeAlcance();
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
            $equipoId = $this->getEquipoId($request);
            $conteos = $this->reporteService->getConteos($from, $to, $agenteIdDeAlcance, $phoneNumberId, $equipoId);

            return new JsonResponse($conteos);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene el volumen de tickets a lo largo del tiempo.
     *
     * GET /api/reportes/volumen?desde=2026-01-01T00:00:00Z&hasta=2026-01-02T00:00:00Z
     */
    #[Route('/api/reportes/volumen', name: 'app_report_volume', methods: ['GET'])]
    public function volumen(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));

            $agenteIdDeAlcance = $this->getAgenteIdDeAlcance();
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
            $equipoId = $this->getEquipoId($request);
            $volumen = $this->reporteService->getVolumen($from, $to, $agenteIdDeAlcance, $phoneNumberId, $equipoId);

            return new JsonResponse($volumen);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene las métricas de rendimiento de los agentes.
     *
     * GET /api/reportes/rendimiento-de-agentes?desde=2026-01-01T00:00:00Z&hasta=2026-01-02T00:00:00Z[&agenteId=1]
     */
    #[Route('/api/reportes/rendimiento-de-agentes', name: 'app_report_agents', methods: ['GET'])]
    public function agentes(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));
            $agenteId = $request->query->get('agenteId') ? (int) $request->query->get('agenteId') : null;

            // Alcance por responsabilidad: forzar agenteId para el rol AGENT
            $agenteIdDeAlcance = $this->getAgenteIdDeAlcance();
            if (null !== $agenteIdDeAlcance) {
                $agenteId = $agenteIdDeAlcance;
            }

            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
            $equipoId = $this->getEquipoId($request);
            $rendimiento = $this->reporteService->getRendimientoDeAgentes($from, $to, $agenteId, $phoneNumberId, $equipoId);
            return new JsonResponse($rendimiento);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene, por agente, la cantidad de tickets activos frente a la capacidad máxima.
     *
     * GET /api/reportes/carga-de-agentes[?agenteId=1]
     */
    #[Route('/api/reportes/carga-de-agentes', name: 'app_report_agent_load', methods: ['GET'])]
    public function cargaDeAgentes(Request $request): JsonResponse
    {
        $agenteId = $request->query->get('agenteId') ? (int) $request->query->get('agenteId') : null;

        $agenteIdDeAlcance = $this->getAgenteIdDeAlcance();
        if (null !== $agenteIdDeAlcance) {
            $agenteId = $agenteIdDeAlcance;
        }

        $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
        $metrics = $this->reporteService->getCargaDeAgentes($agenteId, $phoneNumberId);

        return new JsonResponse($metrics);
    }

    /**
     * Obtiene los tickets sin asignar (sin agente asignado).
     *
     * GET /api/reportes/sin-asignar[?desde=...][&hasta=...]
     */
    #[Route('/api/reportes/sin-asignar', name: 'app_report_unassigned', methods: ['GET'])]
    public function sinAsignar(Request $request): JsonResponse
    {
        try {
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
            $from = $request->query->get('desde') ? $this->parseDateTime($request->query->get('desde')) : null;
            $to = $request->query->get('hasta') ? $this->parseDateTime($request->query->get('hasta')) : null;

            $result = $this->reporteService->getSinAsignar($phoneNumberId, $from, $to);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene los agentes disponibles en este momento (en turno y por debajo de su capacidad).
     *
     * GET /api/reportes/agentes-disponibles
     */
    #[Route('/api/reportes/agentes-disponibles', name: 'app_report_available_agents', methods: ['GET'])]
    public function agentesDisponibles(Request $request): JsonResponse
    {
        $result = $this->listarAgentesDisponibles->execute($this->getPhoneNumberIdDeAlcance(), new \DateTimeImmutable());

        return new JsonResponse($result);
    }

    /**
     * Obtiene el FRT agregado por día dentro de un rango de fechas.
     *
     * GET /api/reportes/tendencia-de-frt?desde=...&hasta=...
     */
    #[Route('/api/reportes/tendencia-de-frt', name: 'app_report_frt_trend', methods: ['GET'])]
    public function tendenciaDeFrt(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();

            $tendencia = $this->reporteService->getTendenciaDeFrt($from, $to, $phoneNumberId);

            return new JsonResponse($tendencia);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene el volumen de tickets agrupado por día de la semana x hora para el mapa de calor.
     *
     * GET /api/reportes/mapa-de-calor?desde=...&hasta=...
     */
    #[Route('/api/reportes/mapa-de-calor', name: 'app_report_heatmap', methods: ['GET'])]
    public function mapaDeCalor(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();

            $mapaDeCalor = $this->reporteService->getMapaDeCalor($from, $to, $phoneNumberId);

            return new JsonResponse($mapaDeCalor);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene los contactos con >= umbral tickets en los últimos N días.
     *
     * GET /api/reportes/contactos-recurrentes[?umbral=3]
     */
    #[Route('/api/reportes/contactos-recurrentes', name: 'app_report_recurring', methods: ['GET'])]
    public function contactosRecurrentes(Request $request): JsonResponse
    {
        $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
        $umbral = $request->query->get('umbral') ? (int) $request->query->get('umbral') : 3;

        $result = $this->reporteService->getContactosRecurrentes($phoneNumberId, $umbral);

        return new JsonResponse($result);
    }

    /**
     * Obtiene los tickets que salieron del estado resuelto.
     *
     * GET /api/reportes/reabiertos?desde=...&hasta=...
     */
    #[Route('/api/reportes/reabiertos', name: 'app_report_reopened', methods: ['GET'])]
    public function reabiertos(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();

            $result = $this->reporteService->getReabiertos($from, $to, $phoneNumberId);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Exporta la lista filtrada de tickets como una descarga CSV en streaming.
     *
     * GET /api/reportes/exportar?desde=...&hasta=...[&agenteId=1][&estado=...][&etiqueta=...][&equipoId=...]
     *
     * Restringido a supervisores (exportación masiva de PII): `#[IsGranted('ROLE_SUPERVISOR')]`.
     * `ROLE_ADMINISTRADOR` lo hereda mediante el `role_hierarchy` de `security.yaml`. La semántica
     * de los filtros (lista blanca de estados, semántica AND de etiquetas, máximo 5 etiquetas) coincide
     * exactamente con `ListTicketsQuery` mediante el `FiltrosDeTicketBuilder` compartido.
     */
    #[IsGranted('ROLE_SUPERVISOR')]
    #[Route('/api/reportes/exportar', name: 'app_report_export', methods: ['GET'])]
    public function exportar(Request $request): Response
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $agenteId = $request->query->get('agenteId');
        $estado = $request->query->get('estado');
        $etiqueta = $request->query->get('etiqueta');
        $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
        $equipoId = $this->getEquipoId($request);

        // Validar los argumentos de filtro de antemano (sin query a la BD; es el mismo builder puro usado
        // dentro de ExportarTicketsCsvQuery::stream) para que una petición inválida (p. ej.
        // >5 etiquetas) falle con 400 antes de enviar cualquier streaming o cabecera.
        try {
            $this->filtrosDeTicketBuilder->build(
                filtro: ($agenteId ? FiltroDeBandeja::MIOS : FiltroDeBandeja::TODOS)->value,
                agenteId: $agenteId,
                estado: $estado,
                phoneNumberId: $phoneNumberId,
                etiqueta: $etiqueta,
                equipoId: $equipoId,
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $filename = sprintf('tickets_%s_%s.csv', $from->format('Y-m-d'), $to->format('Y-m-d'));

        $exportarQuery = $this->exportarTicketsCsvQuery;
        $response = new StreamedResponse(function () use ($exportarQuery, $from, $to, $agenteId, $estado, $etiqueta, $phoneNumberId, $equipoId): void {
            set_time_limit(0);

            $stream = fopen('php://output', 'w+');
            // `Writer::setOutputBOM()` solo tiene efecto mediante los lectores propios de league/csv
            // `download()`/`toString()`, que no son compatibles con
            // escribir por bloques directamente en un stream de salida ya abierto;
            // por eso el BOM se escribe a mano en el stream.
            fwrite($stream, Bom::Utf8->value);

            $writer = Writer::createFromStream($stream);

            $exportarQuery->stream($writer, $from, $to, $agenteId, $estado, $etiqueta, $phoneNumberId, $equipoId);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Obtiene las métricas de demora de respuesta agregadas y por agente.
     *
     * GET /api/reportes/demora-de-respuesta?desde=...&hasta=...
     */
    #[Route('/api/reportes/demora-de-respuesta', name: 'app_report_response_delay', methods: ['GET'])]
    public function demoraDeRespuesta(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('desde'));
            $to = $this->parseDateTime($request->query->get('hasta'));
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();

            $result = $this->reporteService->getDemoraDeRespuesta($from, $to, $phoneNumberId);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Obtiene el detalle de participación por ticket.
     *
     * GET /api/reportes/tickets/{id}/participacion
     */
    #[Route('/api/reportes/tickets/{id}/participacion', name: 'app_report_ticket_participation', methods: ['GET'])]
    public function participacionEnTicket(Request $request, int $id): JsonResponse
    {
        $result = $this->reporteService->getParticipacionEnTicket($id);

        if ($result === null) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        return new JsonResponse($result);
    }
}
