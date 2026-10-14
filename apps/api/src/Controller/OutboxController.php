<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Service\OutboxPublisherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class OutboxController extends AbstractController
{
    public function __construct(
        private readonly OutboxPublisherService $publisher
    ) {}

    #[Route('/admin/outbox/publish', name: 'admin_outbox_publish', methods: ['POST'])]
    public function publish(): JsonResponse
    {
        $count = $this->publisher->publishPending();

        return new JsonResponse([
            'published' => $count,
        ]);
    }
}
