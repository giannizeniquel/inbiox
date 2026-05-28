<?php

namespace App\Controller;

use App\Application\Service\OutboxPublisherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

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