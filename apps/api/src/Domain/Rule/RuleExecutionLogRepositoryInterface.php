<?php

namespace App\Domain\Rule;

interface RuleExecutionLogRepositoryInterface
{
    public function findByExecutionKey(string $executionKey): ?RuleExecutionLog;
    public function save(RuleExecutionLog $executionLog): void;
}