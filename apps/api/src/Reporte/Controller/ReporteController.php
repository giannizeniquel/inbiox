<?php

declare(strict_types=1);

namespace App\Reporte\Controller;

use App\Reporte\Query\ExportarTicketsCsvQuery;
use App\Ticket\Query\FiltrosDeTicketBuilder;
use App\Compartido\Security\AlcanceDeResponsabilidad;
use App\Compartido\Security\AlcanceDeEspacioDeTrabajo;
use App\Reporte\Query\ListarAgentesDisponiblesQuery;
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
 * REST controller for reporting and metrics endpoints.
 *
 * All endpoints require `from` and `to` query parameters in UTC.
 *
 * Authorization: any authenticated agent (ROLE_AGENT or higher) can access
 * every endpoint here. Reports/metrics are NOT restricted by agent — this
 * is an intentional product decision (see docs/04_sprints/sprint_16
 * (Production Readiness).md), not a bug. `getAgenteIdDeAlcance()` below always
 * returns null (see AlcanceDeResponsabilidad), so no per-agent filtering is applied
 * to any query.
 */
#[IsGranted('ROLE_AGENT')]
class ReporteController
{
    private const DATETIME_FORMAT = 'Y-m-d\TH:i:sZ';

    public function __construct(
        private ReporteService $reporteService,
        private ListarAgentesDisponiblesQuery $listarAgentesDisponibles,
        private AlcanceDeResponsabilidad $ownershipScope,
        private AlcanceDeEspacioDeTrabajo $workspaceScope,
        private ExportarTicketsCsvQuery $exportarTicketsCsvQuery,
        private FiltrosDeTicketBuilder $filtrosDeTicketBuilder,
    ) {}

    /**
     * Parse datetime from query parameter.
     *
     * @throws \InvalidArgumentException If datetime is invalid or missing
     */
    private function parseDateTime(?string $value): \DateTimeImmutable
    {
        if ($value === null) {
            throw new \InvalidArgumentException('Missing required parameter: from or to');
        }
        $date = \DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value);
        if ($date === false) {
            throw new \InvalidArgumentException(sprintf('Invalid datetime format: %s. Expected: %s', $value, self::DATETIME_FORMAT));
        }
        return $date;
    }

    /**
     * Get the scoped agent ID for ownership filtering.
     *
     * Always null today for every role (including AGENT) — see
     * AlcanceDeResponsabilidad for why this is intentional and not a bug.
     */
    private function getAgenteIdDeAlcance(): ?int
    {
        return $this->ownershipScope->getAgenteId();
    }

    /**
     * Get the scoped phoneNumberId for workspace filtering.
     * AGENT sees only their active workspace; SUPERVISOR/ADMIN see all.
     */
    private function getPhoneNumberIdDeAlcance(): ?string
    {
        return $this->workspaceScope->getPhoneNumberIdActivo();
    }

    /**
     * Read the optional `teamId` query parameter (single-select team filter).
     */
    private function getEquipoId(Request $request): ?int
    {
        $equipoId = $request->query->get('teamId');

        return null !== $equipoId ? (int) $equipoId : null;
    }

    /**
     * Get First Response Time metrics.
     *
     * GET /reports/frt?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z[&agent_id=1]
     */
    #[Route('/api/reports/frt', name: 'app_report_frt', methods: ['GET'])]
    public function frt(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $agenteId = $request->query->get('agent_id') ? (int) $request->query->get('agent_id') : null;

            // Ownership scoping: force agent_id for AGENT role
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
     * Get Resolution Time metrics.
     *
     * GET /reports/rt?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z
     */
    #[Route('/api/reports/rt', name: 'app_report_rt', methods: ['GET'])]
    public function rt(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));

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
     * Get ticket counts by status.
     *
     * GET /reports/counts?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z
     */
    #[Route('/api/reports/counts', name: 'app_report_counts', methods: ['GET'])]
    public function conteos(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));

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
     * Get ticket volume over time.
     *
     * GET /reports/volume?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z
     */
    #[Route('/api/reports/volume', name: 'app_report_volume', methods: ['GET'])]
    public function volumen(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));

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
     * Get agent performance metrics.
     *
     * GET /reports/agents?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z[&agent_id=1]
     */
    #[Route('/api/reports/agents', name: 'app_report_agents', methods: ['GET'])]
    public function agentes(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $agenteId = $request->query->get('agent_id') ? (int) $request->query->get('agent_id') : null;

            // Ownership scoping: force agent_id for AGENT role
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
     * Get per-agent active ticket count vs max capacity.
     *
     * GET /reports/agent-load[?agent_id=1][&phone_number_id=...]
     */
    #[Route('/api/reports/agent-load', name: 'app_report_agent_load', methods: ['GET'])]
    public function cargaDeAgentes(Request $request): JsonResponse
    {
        $agenteId = $request->query->get('agent_id') ? (int) $request->query->get('agent_id') : null;

        $agenteIdDeAlcance = $this->getAgenteIdDeAlcance();
        if (null !== $agenteIdDeAlcance) {
            $agenteId = $agenteIdDeAlcance;
        }

        $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
        $metrics = $this->reporteService->getCargaDeAgentes($agenteId, $phoneNumberId);

        return new JsonResponse($metrics);
    }

    /**
     * Get unassigned tickets (no agent assigned).
     *
     * GET /reports/unassigned[?from=...][&to=...][&phone_number_id=...]
     */
    #[Route('/api/reports/unassigned', name: 'app_report_unassigned', methods: ['GET'])]
    public function sinAsignar(Request $request): JsonResponse
    {
        try {
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
            $from = $request->query->get('from') ? $this->parseDateTime($request->query->get('from')) : null;
            $to = $request->query->get('to') ? $this->parseDateTime($request->query->get('to')) : null;

            $result = $this->reporteService->getSinAsignar($phoneNumberId, $from, $to);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get agents currently available (in-shift and under capacity).
     *
     * GET /reports/available-agents[?phone_number_id=...]
     */
    #[Route('/api/reports/available-agents', name: 'app_report_available_agents', methods: ['GET'])]
    public function agentesDisponibles(Request $request): JsonResponse
    {
        $result = $this->listarAgentesDisponibles->execute($this->getPhoneNumberIdDeAlcance(), new \DateTimeImmutable());

        return new JsonResponse($result);
    }

    /**
     * Get FRT aggregated by day within a date range.
     *
     * GET /reports/frt-trend?from=...&to=...[&phone_number_id=...]
     */
    #[Route('/api/reports/frt-trend', name: 'app_report_frt_trend', methods: ['GET'])]
    public function tendenciaDeFrt(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();

            $tendencia = $this->reporteService->getTendenciaDeFrt($from, $to, $phoneNumberId);

            return new JsonResponse($tendencia);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get ticket volume grouped by day-of-week x hour for heatmap.
     *
     * GET /reports/heatmap?from=...&to=...[&phone_number_id=...]
     */
    #[Route('/api/reports/heatmap', name: 'app_report_heatmap', methods: ['GET'])]
    public function mapaDeCalor(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();

            $mapaDeCalor = $this->reporteService->getMapaDeCalor($from, $to, $phoneNumberId);

            return new JsonResponse($mapaDeCalor);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get contacts with >= threshold tickets in the last N days.
     *
     * GET /reports/recurring-customers[?phone_number_id=...][&threshold=3]
     */
    #[Route('/api/reports/recurring-customers', name: 'app_report_recurring', methods: ['GET'])]
    public function contactosRecurrentes(Request $request): JsonResponse
    {
        $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
        $umbral = $request->query->get('threshold') ? (int) $request->query->get('threshold') : 3;

        $result = $this->reporteService->getContactosRecurrentes($phoneNumberId, $umbral);

        return new JsonResponse($result);
    }

    /**
     * Get tickets that transitioned out of resolved status.
     *
     * GET /reports/reopened?from=...&to=...[&phone_number_id=...]
     */
    #[Route('/api/reports/reopened', name: 'app_report_reopened', methods: ['GET'])]
    public function reabiertos(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();

            $result = $this->reporteService->getReabiertos($from, $to, $phoneNumberId);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Export the filtered ticket list as a streamed CSV download.
     *
     * GET /reports/export?from=...&to=...[&agent_id=1][&status=...][&tag=...]
     *
     * Supervisor-gated (bulk PII export): `#[IsGranted('ROLE_SUPERVISOR')]`.
     * `ROLE_ADMIN` inherits via `security.yaml`'s `role_hierarchy`. Filter
     * semantics (status whitelist, tag AND-semantics, max 5 tags) match
     * `ListTicketsQuery` exactly via the shared `FiltrosDeTicketBuilder`.
     */
    #[IsGranted('ROLE_SUPERVISOR')]
    #[Route('/api/reports/export', name: 'app_report_export', methods: ['GET'])]
    public function exportar(Request $request): Response
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $agenteId = $request->query->get('agent_id');
        $estado = $request->query->get('status');
        $etiqueta = $request->query->get('tag');
        $phoneNumberId = $this->getPhoneNumberIdDeAlcance();
        $equipoId = $this->getEquipoId($request);

        // Validate filter args up front (no DB query — same pure builder used
        // inside ExportarTicketsCsvQuery::stream) so an invalid request (e.g.
        // >5 tags) fails with 400 before any streaming/headers are sent.
        try {
            $this->filtrosDeTicketBuilder->build(
                filtro: $agenteId ? 'mine' : 'all',
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
            // `Writer::setOutputBOM()` only takes effect via league/csv's own
            // `download()`/`toString()` readers, which is not compatible with
            // writing directly to an already-open output stream in chunks —
            // so the BOM is written to the stream by hand instead.
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
     * Get aggregate + per-agent response-delay metrics.
     *
     * GET /reports/response-delay?from=...&to=...
     */
    #[Route('/api/reports/response-delay', name: 'app_report_response_delay', methods: ['GET'])]
    public function demoraDeRespuesta(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $phoneNumberId = $this->getPhoneNumberIdDeAlcance();

            $result = $this->reporteService->getDemoraDeRespuesta($from, $to, $phoneNumberId);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get per-ticket participation drill-down.
     *
     * GET /reports/tickets/{id}/participation
     */
    #[Route('/api/reports/tickets/{id}/participation', name: 'app_report_ticket_participation', methods: ['GET'])]
    public function participacionEnTicket(Request $request, int $id): JsonResponse
    {
        $result = $this->reporteService->getParticipacionEnTicket($id);

        if ($result === null) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        return new JsonResponse($result);
    }
}
