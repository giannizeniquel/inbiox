<?php

namespace App\Conversacion\Query;

use App\Conversacion\Repository\MensajeRepository;
use App\Ticket\Repository\TicketRepository;

class GetMensajesDeTicketQuery
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private MensajeRepository $mensajeRepository,
    ) {}

    public function execute(
        int $ticketId,
        ?string $cursor = null,
        int $limit = 20,
        string $direccion = 'forward',
        int $offset = 0,
    ): array {
        $limit = min(200, max(1, $limit));
        
        // Get ticket's conversation
        $conversacionId = $this->ticketRepository->findConversacionId($ticketId);

        if ($conversacionId === false) {
            return ['items' => [], 'has_more' => false, 'next_cursor' => null];
        }
        
        // Decode cursor
        $cursorData = null;
        if ($cursor) {
            $decoded = base64_decode($cursor, true);
            if ($decoded) {
                $cursorData = json_decode($decoded, true);
            }
        }
        
        if ($direccion === 'backward') {
            return $this->getMensajesHaciaAtras($conversacionId, $cursorData, $limit, $offset);
        }
        
        return $this->getMensajesHaciaAdelante($conversacionId, $cursorData, $limit);
    }
    
    private function getMensajesHaciaAdelante(int $conversacionId, ?array $cursorData, int $limit): array
    {
        $hasCursor = $cursorData && isset($cursorData['created_at'], $cursorData['id']);
        $results = $this->mensajeRepository->findPaginaAscendente(
            $conversacionId,
            $hasCursor ? $cursorData['created_at'] : null,
            $hasCursor ? (int) $cursorData['id'] : null,
            $limit + 1,
        );

        $hasMore = count($results) > $limit;
        if ($hasMore) {
            array_pop($results);
        }

        $nextCursor = null;
        if ($hasMore && $results) {
            $last = end($results);
            $nextCursor = base64_encode(json_encode([
                'created_at' => $last['created_at'],
                'id' => (int) $last['id'],
            ]));
        }

        $items = array_map(fn($row) => new MensajeDTO(
            mensajeId: (int) $row['id'],
            direccion: $row['direction'],
            contenido: $row['content'],
            creadoAt: (new \DateTimeImmutable($row['created_at']))->format('Y-m-d\TH:i:s\Z'),
            adjunto: is_string($row['media'] ?? null) ? json_decode($row['media'], true) : $row['media'] ?? null,
            agenteRemitenteId: isset($row['sender_agent_id']) ? (int) $row['sender_agent_id'] : null,
            tipoDeRemitente: $row['sender_type'] ?? null,
        ), $results);

        return ['items' => $items, 'has_more' => $hasMore, 'next_cursor' => $nextCursor];
    }

    private function getMensajesHaciaAtras(int $conversacionId, ?array $cursorData, int $limit, int $offset = 0): array
    {
        $hasCursor = $cursorData && isset($cursorData['created_at'], $cursorData['id']);
        $results = $this->mensajeRepository->findPaginaDescendente(
            $conversacionId,
            $hasCursor ? $cursorData['created_at'] : null,
            $hasCursor ? (int) $cursorData['id'] : null,
            $limit + 1,
            $offset,
        );

        $hasMore = count($results) > $limit;
        if ($hasMore) {
            array_pop($results);
        }

        // Reverse to chronological
        $results = array_reverse($results);

        $nextCursor = null;
        if ($hasMore && $results) {
            $first = $results[0];
            $nextCursor = base64_encode(json_encode([
                'created_at' => $first['created_at'],
                'id' => (int) $first['id'],
            ]));
        }

        $items = array_map(fn($row) => new MensajeDTO(
            mensajeId: (int) $row['id'],
            direccion: $row['direction'],
            contenido: $row['content'],
            creadoAt: (new \DateTimeImmutable($row['created_at']))->format('Y-m-d\TH:i:s\Z'),
            adjunto: is_string($row['media'] ?? null) ? json_decode($row['media'], true) : $row['media'] ?? null,
            agenteRemitenteId: isset($row['sender_agent_id']) ? (int) $row['sender_agent_id'] : null,
            tipoDeRemitente: $row['sender_type'] ?? null,
        ), $results);

        return ['items' => $items, 'has_more' => $hasMore, 'next_cursor' => $nextCursor];
    }
}