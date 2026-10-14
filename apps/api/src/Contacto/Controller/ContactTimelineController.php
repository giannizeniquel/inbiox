<?php

declare(strict_types=1);

namespace App\Contacto\Controller;

use App\Contacto\Query\TimelineEventDTO;
use App\Contacto\Query\GetContactTimelineQuery;
use App\Compartido\Security\WorkspaceScope;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class ContactTimelineController
{
    public function __construct(
        private GetContactTimelineQuery $getContactTimelineQuery,
        private WorkspaceScope $workspaceScope,
        private LoggerInterface $logger,
    ) {}

    #[Route('/api/contacts/{id}/timeline', name: 'app_contacts_timeline', methods: ['GET'])]
    public function getTimeline(int $id, Request $request): JsonResponse
    {
        try {
            $before = $request->query->get('before');
            $limit = min(50, max(1, (int) $request->query->get('limit', 20)));
            $phoneNumberId = $this->workspaceScope->getActivePhoneNumberId();

            $result = $this->getContactTimelineQuery->execute(
                contactId: $id,
                before: $before,
                limit: $limit,
                phoneNumberId: $phoneNumberId,
            );

            $events = array_map(
                fn (TimelineEventDTO $dto) => $dto->toArray(),
                $result['events'],
            );

            return new JsonResponse([
                'events' => $events,
                'cursor' => $result['cursor'],
                'hasMore' => $result['hasMore'],
            ]);
        } catch (\Exception $e) {
            $referenceId = bin2hex(random_bytes(8));
            $this->logger->error('Error fetching contact timeline: {message}', [
                'reference' => $referenceId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => 'Internal server error', 'reference' => $referenceId], 500);
        }
    }
}
