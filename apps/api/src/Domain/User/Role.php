<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Defines the roles available in the system.
 *
 * Hierarchy (enforced via security.yaml role_hierarchy):
 *   ROLE_ADMIN → ROLE_SUPERVISOR → ROLE_AGENT → ROLE_USER
 */
enum Role: string
{
    case ADMIN = 'ROLE_ADMIN';
    case SUPERVISOR = 'ROLE_SUPERVISOR';
    case AGENT = 'ROLE_AGENT';
}
