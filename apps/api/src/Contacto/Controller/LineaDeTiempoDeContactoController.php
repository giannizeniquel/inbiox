<?php

declare(strict_types=1);

namespace App\Contacto\Controller;

use App\Contacto\Query\EventoDeLineaDeTiempoDTO;
use App\Contacto\Query\GetLineaDeTiempoDeContactoQuery;
use App\Compartido\Security\AlcanceDeEspacioDeTrabajo;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class LineaDeTiempoDeContactoController
{
    public function __construct(
        private GetLineaDeTiempoDeContactoQuery $getLineaDeTiempoDeContactoQuery,
        private AlcanceDeEspacioDeTrabajo $workspaceScope,
    ) {}

    #[Route('/api/contacts/{id}/timeline', name: 'app_contacts_timeline', methods: ['GET'])]
    public function getLineaDeTiempo(int $id, Request $request): JsonResponse
    {
        $before = $request->query->get('before');
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));
        $phoneNumberId = $this->workspaceScope->getActivePhoneNumberId();

        $result = $this->getLineaDeTiempoDeContactoQuery->execute(
            contactoId: $id,
            before: $before,
            limit: $limit,
            phoneNumberId: $phoneNumberId,
        );

        $eventos = array_map(
            fn (EventoDeLineaDeTiempoDTO $dto) => $dto->toArray(),
            $result['events'],
        );

        return new JsonResponse([
            'events' => $eventos,
            'cursor' => $result['cursor'],
            'hasMore' => $result['hasMore'],
        ]);
    }
}
