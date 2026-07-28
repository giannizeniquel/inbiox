<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Security\OwnershipScope;
use App\Application\Security\WorkspaceScope;
use App\Application\Service\ReportingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * REST controller for reporting and metrics endpoints.
 *
 * All endpoints require `from` and `to` query parameters in UTC.
 * Authorization:
 * - AGENT: sees only their own metrics (via OwnershipScope)
 * - SUPERVISOR/ADMIN: sees all metrics
 */
#[IsGranted('ROLE_AGENT')]
class ReportingController
{
    private const DATETIME_FORMAT = 'Y-m-d\TH:i:sZ';

    public function __construct(
        private ReportingService $reportingService,
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
     * AGENT sees only their metrics; SUPERVISOR/ADMIN see all.
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

            $performance = $this->reportingService->getAgentPerformance($from, $to, $agentId);
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
}
