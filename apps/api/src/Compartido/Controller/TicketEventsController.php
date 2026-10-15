<?php

namespace App\Compartido\Controller;

use App\Compartido\Query\ResyncTicketEventsQuery;
use App\Compartido\Realtime\MercureJwtService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Provides sequence-based resync of outbox events for a ticket.
 *
 * The frontend calls this after reconnection or gap detection to recover
 * missed events. JWT auth mirrors the Mercure SSE subscription model:
 * the JWT must include "ticket/{id}" in its subscribe topics.
 *
 * Response shape uses `events`/`lastSequence`/`hasMore` (NOT data/meta).
 *
 * NOTE: The `/api/mercure/token` endpoint has been moved to MercureTokenController.
 * This controller only handles ticket event resync.
 */
final class TicketEventsController
{
    public function __construct(
        private readonly ResyncTicketEventsQuery $resyncTicketEventsQuery,
        private readonly MercureJwtService $jwtService,
    ) {}

    #[Route('/api/tickets/{id}/events', name: 'app_ticket_events_resync', methods: ['GET'])]
    public function listSince(int $id, Request $request): JsonResponse
    {
        // 1. Auth: validate JWT
        $token = $this->resolveJwt($request);
        if ($token === null) {
            return new JsonResponse(['error' => 'Unauthorized — missing or invalid JWT'], 401);
        }

        // 2. Validate JWT topic includes 'tickets'
        $topics = $this->jwtService->extractTopics($token);
        if ($topics === null || !\in_array('tickets', $topics, true)) {
            return new JsonResponse(['error' => 'Forbidden — JWT does not grant access to this ticket'], 403);
        }

        // 3. Validate afterSequence parameter
        $afterSequence = $request->query->getInt('afterSequence', 0);
        if ($afterSequence < -1) {
            return new JsonResponse(['error' => 'afterSequence must be >= -1'], 400);
        }

        return new JsonResponse($this->resyncTicketEventsQuery->execute($id, $afterSequence));
    }

    /**
     * Resolve JWT from Authorization header or mercureAuthorization cookie.
     */
    private function resolveJwt(Request $request): ?\Lcobucci\JWT\Token
    {
        // Try Authorization header first
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $tokenString = substr($authHeader, 7);
            $token = $this->jwtService->verifyToken($tokenString);
            if ($token !== null) {
                return $token;
            }
        }

        // Fall back to mercureAuthorization cookie (same cookie the Mercure hub uses)
        $cookieToken = $request->cookies->get('mercureAuthorization');
        if ($cookieToken !== null) {
            $token = $this->jwtService->verifyToken($cookieToken);
            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }
}
