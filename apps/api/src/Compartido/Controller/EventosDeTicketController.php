<?php

namespace App\Compartido\Controller;

use App\Compartido\Query\ResincronizarEventosDeTicketQuery;
use App\Compartido\Realtime\MercureJwtService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ofrece la resincronización por sequence de los eventos del outbox de un ticket.
 *
 * El frontend lo llama después de una reconexión o de detectar un hueco para recuperar
 * los eventos perdidos. La autenticación JWT replica el modelo de suscripción SSE de Mercure:
 * el JWT debe incluir "ticket/{id}" en sus topics de suscripción.
 *
 * La forma de la respuesta usa `events`/`lastSequence`/`hasMore` (NO data/meta).
 *
 * NOTA: el endpoint `/api/mercure/token` se movió a MercureTokenController.
 * Este controlador solo se ocupa de la resincronización de eventos de ticket.
 */
final class EventosDeTicketController
{
    public function __construct(
        private readonly ResincronizarEventosDeTicketQuery $resincronizarEventosDeTicketQuery,
        private readonly MercureJwtService $jwtService,
    ) {}

    #[Route('/api/tickets/{id}/events', name: 'app_ticket_events_resync', methods: ['GET'])]
    public function listarDesde(int $id, Request $request): JsonResponse
    {
        // 1. Auth: valida el JWT
        $token = $this->resolverJwt($request);
        if ($token === null) {
            return new JsonResponse(['error' => 'Unauthorized — missing or invalid JWT'], 401);
        }

        // 2. Valida que el topic del JWT incluya 'tickets'
        $topics = $this->jwtService->extractTopics($token);
        if ($topics === null || !\in_array('tickets', $topics, true)) {
            return new JsonResponse(['error' => 'Forbidden — JWT does not grant access to this ticket'], 403);
        }

        // 3. Valida el parámetro afterSequence
        $afterSequence = $request->query->getInt('afterSequence', 0);
        if ($afterSequence < -1) {
            return new JsonResponse(['error' => 'afterSequence must be >= -1'], 400);
        }

        return new JsonResponse($this->resincronizarEventosDeTicketQuery->execute($id, $afterSequence));
    }

    /**
     * Resuelve el JWT desde el header Authorization o la cookie mercureAuthorization.
     */
    private function resolverJwt(Request $request): ?\Lcobucci\JWT\Token
    {
        // Primero intenta con el header Authorization
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $tokenString = substr($authHeader, 7);
            $token = $this->jwtService->verifyToken($tokenString);
            if ($token !== null) {
                return $token;
            }
        }

        // Si no, usa la cookie mercureAuthorization (la misma cookie que usa el hub de Mercure)
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
