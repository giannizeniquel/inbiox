<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ticket;

use App\Ticket\TicketResponsabilidadGuard;
use App\Ticket\EstadoDeTicket;
use PHPUnit\Framework\TestCase;

/**
 * Guard truth table (status x senderIsPrivileged x owner x agentId<=0) for
 * the resolved/closed ownership guard (design.md D3-D5, spec.md "Resolved/
 * Closed Ticket Ownership Guard"). Zero mocks — the guard is a pure function.
 */
final class TicketOwnershipGuardTest extends TestCase
{
    public function test_active_status_never_blocks_regardless_of_other_inputs(): void
    {
        self::assertFalse(TicketResponsabilidadGuard::blocksReassignment(
            status: EstadoDeTicket::ACTIVE->value,
            senderIsPrivileged: false,
            agentId: 7,
            assignedAgentId: 3,
        ));
    }

    public function test_resolved_ticket_blocks_non_owning_non_privileged_agent(): void
    {
        self::assertTrue(TicketResponsabilidadGuard::blocksReassignment(
            status: EstadoDeTicket::RESOLVED->value,
            senderIsPrivileged: false,
            agentId: 7,
            assignedAgentId: 3,
        ));
    }

    public function test_closed_ticket_blocks_non_owning_non_privileged_agent(): void
    {
        self::assertTrue(TicketResponsabilidadGuard::blocksReassignment(
            status: EstadoDeTicket::CLOSED->value,
            senderIsPrivileged: false,
            agentId: 7,
            assignedAgentId: 3,
        ));
    }

    public function test_privileged_sender_bypasses_the_guard(): void
    {
        self::assertFalse(TicketResponsabilidadGuard::blocksReassignment(
            status: EstadoDeTicket::RESOLVED->value,
            senderIsPrivileged: true,
            agentId: 7,
            assignedAgentId: 3,
        ));
    }

    public function test_owning_agent_reply_does_not_block(): void
    {
        self::assertFalse(TicketResponsabilidadGuard::blocksReassignment(
            status: EstadoDeTicket::CLOSED->value,
            senderIsPrivileged: false,
            agentId: 3,
            assignedAgentId: 3,
        ));
    }

    public function test_unassigned_resolved_ticket_blocks_non_privileged_agent(): void
    {
        self::assertTrue(TicketResponsabilidadGuard::blocksReassignment(
            status: EstadoDeTicket::RESOLVED->value,
            senderIsPrivileged: false,
            agentId: 7,
            assignedAgentId: null,
        ));
    }

    public function test_agent_id_zero_never_blocks(): void
    {
        self::assertFalse(TicketResponsabilidadGuard::blocksReassignment(
            status: EstadoDeTicket::RESOLVED->value,
            senderIsPrivileged: false,
            agentId: 0,
            assignedAgentId: 3,
        ));
    }

    public function test_is_resolved_or_closed_helper(): void
    {
        self::assertTrue(TicketResponsabilidadGuard::isResolvedOrClosed(EstadoDeTicket::RESOLVED->value));
        self::assertTrue(TicketResponsabilidadGuard::isResolvedOrClosed(EstadoDeTicket::CLOSED->value));
        self::assertFalse(TicketResponsabilidadGuard::isResolvedOrClosed(EstadoDeTicket::NEW->value));
        self::assertFalse(TicketResponsabilidadGuard::isResolvedOrClosed(EstadoDeTicket::ACTIVE->value));
        self::assertFalse(TicketResponsabilidadGuard::isResolvedOrClosed(EstadoDeTicket::WAITING->value));
    }
}
