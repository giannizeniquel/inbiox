<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\AddTagToTicketUseCase;
use App\Application\UseCase\RemoveTagFromTicketUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class TagController extends AbstractController
{
    public function __construct(
        private AddTagToTicketUseCase $addTagUseCase,
        private RemoveTagFromTicketUseCase $removeTagUseCase,
    ) {}

    /**
     * Add a tag to a ticket
     * POST /api/tickets/{ticketId}/tags
     */
    #[Route('/api/tickets/{ticketId}/tags', methods: ['POST'])]
    public function addTag(int $ticketId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $tagName = $data['tag'] ?? $request->request->get('tag');

        if (!$tagName) {
            return new JsonResponse(['error' => 'tag is required'], 400);
        }

        try {
            $result = $this->addTagUseCase->execute($ticketId, $tagName, 'manual');
            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Remove a tag from a ticket
     * DELETE /api/tickets/{ticketId}/tags/{tagName}
     */
    #[Route('/api/tickets/{ticketId}/tags/{tagName}', methods: ['DELETE'])]
    public function removeTag(int $ticketId, string $tagName): JsonResponse
    {
        try {
            $result = $this->removeTagUseCase->execute($ticketId, $tagName, 'manual');
            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }
    }
}
