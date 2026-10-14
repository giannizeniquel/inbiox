<?php

namespace App\Automatizacion;

interface RuleInterface
{
    /**
     * Check if this rule supports the given context.
     */
    public function supports(RuleContext $context): bool;

    /**
     * Execute the rule's action.
     */
    public function execute(RuleContext $context): void;

    /**
     * Get unique name for this rule.
     */
    public function getName(): string;

    /**
     * Build execution key for idempotency check.
     */
    public function buildExecutionKey(RuleContext $context): string;
}