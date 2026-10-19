<?php

namespace App\Automatizacion;

interface ReglaInterface
{
    /**
     * Check if this rule supports the given context.
     */
    public function supports(ContextoDeRegla $context): bool;

    /**
     * Execute the rule's action.
     */
    public function execute(ContextoDeRegla $context): void;

    /**
     * Get unique name for this rule.
     */
    public function getName(): string;

    /**
     * Build execution key for idempotency check.
     */
    public function buildExecutionKey(ContextoDeRegla $context): string;
}