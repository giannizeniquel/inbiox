<?php

namespace App\Application\DTO;

class PaginationMetaDTO
{
    public function __construct(
        public int $page,
        public int $limit,
        public int $total,
        public bool $hasNext,
        public ?string $nextCursor = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'page' => $this->page,
            'limit' => $this->limit,
            'total' => $this->total,
            'has_next' => $this->hasNext,
        ];

        if ($this->nextCursor !== null) {
            $data['next_cursor'] = $this->nextCursor;
        }

        return $data;
    }
}
