<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Service\MercureJwtService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class MercureTokenController extends AbstractController
{
    public function __construct(
        private readonly MercureJwtService $jwtService,
    ) {}

    #[Route('/api/mercure/token', methods: ['GET'])]
    public function getToken(): JsonResponse
    {
        $token = $this->jwtService->createSubscriberToken(['tickets']);
        return new JsonResponse(['token' => $token->toString()]);
    }
}
