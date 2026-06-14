<?php

namespace App\Application\Query;

use App\Application\DTO\MessageDTO;
use Doctrine\ORM\EntityManagerInterface;

class GetTicketMessagesQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function execute(
        int $ticketId,
        ?string $cursor = null,
        int $limit = 200,
        string $direction = 'forward'
    ): array {
        $limit = min(100, max(1, $limit));
        
        // Get ticket's conversation
        $ticketSql = 'SELECT conversation_id FROM tickets WHERE id = ?';
        $ticketStmt = $this->entityManager->getConnection()->executeQuery($ticketSql, [$ticketId]);
        $ticketRow = $ticketStmt->fetchAssociative();
        
        if (!$ticketRow) {
            return ['items' => [], 'has_more' => false, 'next_cursor' => null];
        }
        
        $conversationId = $ticketRow['conversation_id'];
        
        // Decode cursor
        $cursorData = null;
        if ($cursor) {
            $decoded = base64_decode($cursor, true);
            if ($decoded) {
                $cursorData = json_decode($decoded, true);
            }
        }
        
        if ($direction === 'backward') {
            return $this->getMessagesBackward($conversationId, $cursorData, $limit);
        }
        
        return $this->getMessagesForward($conversationId, $cursorData, $limit);
    }
    
    private function getMessagesForward(int $conversationId, ?array $cursorData, int $limit): array
    {
        $params = [$conversationId];
        $where = 'conversation_id = ?';
        
        if ($cursorData && isset($cursorData['created_at'], $cursorData['id'])) {
            $where .= ' AND (created_at > ? OR (created_at = ? AND id > ?))';
            $params[] = $cursorData['created_at'];
            $params[] = $cursorData['created_at'];
            $params[] = (int) $cursorData['id'];
        }
        
        $sql = "SELECT id, content, direction, created_at
                FROM messages
                WHERE $where
                ORDER BY created_at ASC, id ASC
                LIMIT " . ($limit + 1);
        
        $stmt = $this->entityManager->getConnection()->executeQuery($sql, $params);
        $results = $stmt->fetchAllAssociative();
        
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
        
        $items = array_map(fn($row) => new MessageDTO(
            messageId: (int) $row['id'],
            direction: $row['direction'],
            content: $row['content'],
            createdAt: (new \DateTimeImmutable($row['created_at']))->format('Y-m-d\TH:i:s\Z'),
        ), $results);
        
        return ['items' => $items, 'has_more' => $hasMore, 'next_cursor' => $nextCursor];
    }
    
    private function getMessagesBackward(int $conversationId, ?array $cursorData, int $limit): array
    {
        $params = [$conversationId];
        $where = 'conversation_id = ?';
        
        if ($cursorData && isset($cursorData['created_at'], $cursorData['id'])) {
            $where .= ' AND (created_at < ? OR (created_at = ? AND id < ?))';
            $params[] = $cursorData['created_at'];
            $params[] = $cursorData['created_at'];
            $params[] = (int) $cursorData['id'];
        }
        
        $sql = "SELECT id, content, direction, created_at
                FROM messages
                WHERE $where
                ORDER BY created_at DESC, id DESC
                LIMIT " . ($limit + 1);
        
        $stmt = $this->entityManager->getConnection()->executeQuery($sql, $params);
        $results = $stmt->fetchAllAssociative();
        
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
        
        $items = array_map(fn($row) => new MessageDTO(
            messageId: (int) $row['id'],
            direction: $row['direction'],
            content: $row['content'],
            createdAt: (new \DateTimeImmutable($row['created_at']))->format('Y-m-d\TH:i:s\Z'),
        ), $results);
        
        return ['items' => $items, 'has_more' => $hasMore, 'next_cursor' => $nextCursor];
    }
}