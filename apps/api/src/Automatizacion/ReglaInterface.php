<?php

namespace App\Automatizacion;

interface ReglaInterface
{
    /**
     * Check if this rule supports the given context.
     */
    public function supports(ContextoDeRegla $contexto): bool;

    /**
     * Execute the rule's action.
     */
    public function execute(ContextoDeRegla $contexto): void;

    /**
     * Get unique name for this rule.
     */
    public function getNombre(): string;

    /**
     * Build execution key for idempotency check.
     */
    public function armarClaveDeEjecucion(ContextoDeRegla $contexto): string;
}