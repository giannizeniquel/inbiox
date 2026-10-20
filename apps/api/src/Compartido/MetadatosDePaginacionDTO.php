<?php

namespace App\Compartido;

class MetadatosDePaginacionDTO
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
            'haySiguiente' => $this->hasNext,
        ];

        if ($this->nextCursor !== null) {
            $data['siguienteCursor'] = $this->nextCursor;
        }

        return $data;
    }
}
