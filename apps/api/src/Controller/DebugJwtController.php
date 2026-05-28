<?php

namespace App\Controller;

use App\Application\Service\MercureJwtService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class DebugJwtController extends AbstractController
{
    public function __construct(
        private readonly MercureJwtService $jwtService
    ) {}

    #[Route('/debug/jwt/{agentId}', name: 'debug_jwt', methods: ['GET'])]
    public function generate(int $agentId): JsonResponse
    {
        try {
            // Use some test ticket IDs
            $tokens = [
                'ticket/1',
                'ticket/2',
                'ticket/3',
            ];
            
            $token = $this->jwtService->generateToken($agentId, $tokens);
            
            return new JsonResponse([
                'token' => $token,
                ' agent_id' => $agentId,
                'topics' => $tokens,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}