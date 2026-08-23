<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Security\OwnershipScope;
use App\Application\Security\WorkspaceScope;
use App\Application\Service\AgentAvailabilityService;
use App\Application\Service\ReportingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * REST controller for reporting and metrics endpoints.
 *
 * All endpoints require `from` and `to` query parameters in UTC.
 *
 * Authorization: any authenticated agent (ROLE_AGENT or higher) can access
 * every endpoint here. Reports/metrics are NOT restricted by agent — this
 * is an intentional product decision (see docs/04_sprints/sprint_16
 * (Production Readiness).md), not a bug. `getScopeAgentId()` below always
 * returns null (see OwnershipScope), so no per-agent filtering is applied
 * to any query. `ReportVoter::VIEW_REPORT` exists but is not used by this
 * controller; it is reserved for a possible future access restriction.
 */
#[IsGranted('ROLE_AGENT')]
class ReportingController
{
    private const DATETIME_FORMAT = 'Y-m-d\TH:i:sZ';

    public function __construct(
        private ReportingService $reportingService,
        private AgentAvailabilityService $agentAvailabilityService,
        private EntityManagerInterface $entityManager,
        private OwnershipScope $ownershipScope,
        private WorkspaceScope $workspaceScope,
        private LoggerInterface $logger
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
     * OwnershipScope for why this is intentional and not a bug.
     */
    private function getScopeAgentId(): ?int
    {
        return $this->ownershipScope->getAgentId();
    }

    /**
     * Get the scoped phoneNumberId for workspace filtering.
     * AGENT sees only their active workspace; SUPERVISOR/ADMIN see all.
     */
    private function getScopePhoneNumberId(): ?string
    {
        return $this->workspaceScope->getActivePhoneNumberId();
    }

    /**
     * Get First Response Time metrics.
     *
     * GET /reports/frt?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z[&agent_id=1]
     */
    #[Route('/reports/frt', methods: ['GET'])]
    public function frt(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $agentId = $request->query->get('agent_id') ? (int) $request->query->get('agent_id') : null;

            // Ownership scoping: force agent_id for AGENT role
            $scopeAgentId = $this->getScopeAgentId();
            if (null !== $scopeAgentId) {
                $agentId = $scopeAgentId;
            }

            $phoneNumberId = $this->getScopePhoneNumberId();

            $metrics = $this->reportingService->getFrt($from, $to, $agentId, $phoneNumberId);
            return new JsonResponse($metrics);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching FRT metrics: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get Resolution Time metrics.
     *
     * GET /reports/rt?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z
     */
    #[Route('/reports/rt', methods: ['GET'])]
    public function rt(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));

            $scopeAgentId = $this->getScopeAgentId();
            $phoneNumberId = $this->getScopePhoneNumberId();
            $metrics = $this->reportingService->getRt($from, $to, $scopeAgentId, $phoneNumberId);

            return new JsonResponse($metrics);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching RT metrics: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get ticket counts by status.
     *
     * GET /reports/counts?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z
     */
    #[Route('/reports/counts', methods: ['GET'])]
    public function counts(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));

            $scopeAgentId = $this->getScopeAgentId();
            $phoneNumberId = $this->getScopePhoneNumberId();
            $counts = $this->reportingService->getCounts($from, $to, $scopeAgentId, $phoneNumberId);

            return new JsonResponse($counts);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching ticket counts: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get ticket volume over time.
     *
     * GET /reports/volume?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z
     */
    #[Route('/reports/volume', methods: ['GET'])]
    public function volume(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));

            $scopeAgentId = $this->getScopeAgentId();
            $phoneNumberId = $this->getScopePhoneNumberId();
            $volume = $this->reportingService->getVolume($from, $to, $scopeAgentId, $phoneNumberId);

            return new JsonResponse($volume);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching volume: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get agent performance metrics.
     *
     * GET /reports/agents?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z[&agent_id=1]
     */
    #[Route('/reports/agents', methods: ['GET'])]
    public function agents(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $agentId = $request->query->get('agent_id') ? (int) $request->query->get('agent_id') : null;

            // Ownership scoping: force agent_id for AGENT role
            $scopeAgentId = $this->getScopeAgentId();
            if (null !== $scopeAgentId) {
                $agentId = $scopeAgentId;
            }

            $phoneNumberId = $this->getScopePhoneNumberId();
            $performance = $this->reportingService->getAgentPerformance($from, $to, $agentId, $phoneNumberId);
            return new JsonResponse($performance);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching agent performance: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get per-agent active ticket count vs max capacity.
     *
     * GET /reports/agent-load[?agent_id=1][&phone_number_id=...]
     */
    #[Route('/reports/agent-load', methods: ['GET'])]
    public function agentLoad(Request $request): JsonResponse
    {
        try {
            $agentId = $request->query->get('agent_id') ? (int) $request->query->get('agent_id') : null;

            $scopeAgentId = $this->getScopeAgentId();
            if (null !== $scopeAgentId) {
                $agentId = $scopeAgentId;
            }

            $phoneNumberId = $this->getScopePhoneNumberId();
            $metrics = $this->reportingService->getAgentLoad($agentId, $phoneNumberId);

            return new JsonResponse($metrics);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching agent load: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get unassigned tickets (no agent assigned).
     *
     * GET /reports/unassigned[?from=...][&to=...][&phone_number_id=...]
     */
    #[Route('/reports/unassigned', methods: ['GET'])]
    public function unassigned(Request $request): JsonResponse
    {
        try {
            $phoneNumberId = $this->getScopePhoneNumberId();
            $from = $request->query->get('from') ? $this->parseDateTime($request->query->get('from')) : null;
            $to = $request->query->get('to') ? $this->parseDateTime($request->query->get('to')) : null;

            $result = $this->reportingService->getUnassigned($phoneNumberId, $from, $to);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching unassigned tickets: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get agents currently available (in-shift and under capacity).
     *
     * GET /reports/available-agents[?phone_number_id=...]
     */
    #[Route('/reports/available-agents', methods: ['GET'])]
    public function availableAgents(Request $request): JsonResponse
    {
        try {
            $phoneNumberId = $this->getScopePhoneNumberId();
            $now = new \DateTimeImmutable();

            $available = $this->agentAvailabilityService->listAvailableAgents($now);

            $result = [];
            foreach ($available as $agent) {
                // Filter by workspace if scoped
                if ($phoneNumberId !== null) {
                    $isInWorkspace = false;
                    foreach ($agent->getWorkspaces() as $ws) {
                        if ($ws->getPhoneNumberId() === $phoneNumberId) {
                            $isInWorkspace = true;
                            break;
                        }
                    }
                    if (!$isInWorkspace) {
                        continue;
                    }
                }

                $result[] = [
                    'agent_id' => $agent->getId(),
                    'agent_name' => $agent->getName(),
                    'in_shift' => true,
                    'active_tickets' => $this->countActiveTicketsForAgent($agent->getId()),
                ];
            }

            return new JsonResponse($result);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching available agents: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get FRT aggregated by day within a date range.
     *
     * GET /reports/frt-trend?from=...&to=...[&phone_number_id=...]
     */
    #[Route('/reports/frt-trend', methods: ['GET'])]
    public function frtTrend(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $phoneNumberId = $this->getScopePhoneNumberId();

            $trend = $this->reportingService->getFrtTrend($from, $to, $phoneNumberId);

            return new JsonResponse($trend);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching FRT trend: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get ticket volume grouped by day-of-week x hour for heatmap.
     *
     * GET /reports/heatmap?from=...&to=...[&phone_number_id=...]
     */
    #[Route('/reports/heatmap', methods: ['GET'])]
    public function heatmap(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $phoneNumberId = $this->getScopePhoneNumberId();

            $heatmap = $this->reportingService->getHeatmap($from, $to, $phoneNumberId);

            return new JsonResponse($heatmap);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching heatmap: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get contacts with >= threshold tickets in the last N days.
     *
     * GET /reports/recurring-customers[?phone_number_id=...][&threshold=3]
     */
    #[Route('/reports/recurring-customers', methods: ['GET'])]
    public function recurringCustomers(Request $request): JsonResponse
    {
        try {
            $phoneNumberId = $this->getScopePhoneNumberId();
            $threshold = $request->query->get('threshold') ? (int) $request->query->get('threshold') : 3;

            $result = $this->reportingService->getRecurringCustomers($phoneNumberId, $threshold);

            return new JsonResponse($result);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching recurring customers: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Get tickets that transitioned out of resolved status.
     *
     * GET /reports/reopened?from=...&to=...[&phone_number_id=...]
     */
    #[Route('/reports/reopened', methods: ['GET'])]
    public function reopened(Request $request): JsonResponse
    {
        try {
            $from = $this->parseDateTime($request->query->get('from'));
            $to = $this->parseDateTime($request->query->get('to'));
            $phoneNumberId = $this->getScopePhoneNumberId();

            $result = $this->reportingService->getReopened($from, $to, $phoneNumberId);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching reopened tickets: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }

    /**
     * Count active tickets for a specific agent (helper for available-agents).
     */
    private function countActiveTicketsForAgent(int $agentId): int
    {
        $result = $this->entityManager->createQuery(
            'SELECT COUNT(t.id) FROM App\Domain\Ticket\Ticket t
             WHERE t.assignedAgentId = :agentId
             AND t.status IN (:statuses)'
        )
            ->setParameter('agentId', $agentId)
            ->setParameter('statuses', ['new', 'active', 'waiting'])
            ->getSingleScalarResult();

        return (int) $result;
    }
}
