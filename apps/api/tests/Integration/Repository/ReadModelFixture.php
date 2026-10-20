<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;

/**
 * Shared rows for the read-side repository tests of phase 3c-2. They are
 * inserted inside a transaction that tearDown() rolls back, so nothing is
 * left behind. Ids start at 880001; workspaces are 'repo-read-p1' and
 * 'repo-read-p2'.
 *
 * Tickets (status, agent, workspace, team, updated_at):
 *   880001 active   agent 880001  p1  team 880001  2026-01-10
 *   880002 new      -             p1  -            2026-01-10
 *   880003 waiting  agent 880002  p2  team 880002  2026-01-09
 *   880004 resolved agent 880001  p2  team 880001  2026-01-08
 *   880005 closed   agent 880003  -   -            2026-01-07
 *   880006 new      agent 880001  p1  team 880002  2026-01-11
 * Tags: 880001 has vip+urgente, 880003 has otro+vip, 880004 has urgente.
 */
trait ReadModelFixture
{
    private Connection $conn;

    private function beginFixture(Connection $conn): void
    {
        $this->conn = $conn;
        $this->conn->beginTransaction();

        foreach ([
            "INSERT INTO workspaces (id, phone_number_id, display_name, is_active, created_at, updated_at) VALUES (880001, 'repo-read-p1', 'WS', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            "INSERT INTO teams (id, workspace_id, name, is_active, created_at, updated_at) VALUES (880001, 880001, 'T1', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00'), (880002, 880001, 'T2', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            "INSERT INTO `user` (id, email, password_hash, roles, is_active, created_at) VALUES (880001, 'repo-read-sup@x.test', 'x', '[\"ROLE_SUPERVISOR\"]', 1, '2026-01-01 00:00:00'), (880002, 'repo-read-adm@x.test', 'x', '[\"ROLE_ADMIN\"]', 1, '2026-01-01 00:00:00'), (880003, 'repo-read-agent@x.test', 'x', '[\"ROLE_AGENT\"]', 1, '2026-01-01 00:00:00')",
            "INSERT INTO agents (id, name, max_active_tickets, created_at, updated_at, user_id) VALUES (880001, 'Agente Uno', 5, '2026-01-01 00:00:00', '2026-01-01 00:00:00', 880001), (880002, 'Agente Dos', 3, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL), (880003, 'Agente Tres', 10, '2026-01-01 00:00:00', '2026-01-01 00:00:00', 880002)",
            "INSERT INTO contacts (id, phone, name, status, channel, owner_id, email, created_at, updated_at) VALUES
                (880001, '+5488000001', 'Zoe Repo', 'active', 'whatsapp', 880001, 'zoe@repo-read.test', '2026-01-01 00:00:00', '2026-01-05 00:00:00'),
                (880002, '+5488000002', 'Yago Repo', 'inactive', 'other', NULL, NULL, '2026-01-01 00:00:00', '2026-01-05 00:00:00'),
                (880003, '+5488000003', NULL, NULL, NULL, NULL, NULL, '2026-01-01 00:00:00', '2026-01-04 00:00:00')",
            "INSERT INTO conversations (id, status, created_at, updated_at, phone_number_id, contact_id) VALUES
                (880001, 'open', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'repo-read-p1', 880001),
                (880002, 'open', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'repo-read-p1', 880001),
                (880003, 'open', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'repo-read-p2', 880002),
                (880004, 'open', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'repo-read-p2', 880002),
                (880005, 'open', '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL, 880003),
                (880006, 'open', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'repo-read-p1', 880003)",
            "INSERT INTO tickets (id, status, priority, assigned_agent_id, first_response_at, resolved_at, closed_at, created_at, updated_at, phone_number_id, contact_id, conversation_id, last_read_at, last_inbound_message_at, team_id) VALUES
                (880001, 'active', 'high', 880001, '2026-01-01 09:05:00', NULL, NULL, '2026-01-01 09:00:00', '2026-01-10 00:00:00', 'repo-read-p1', 880001, 880001, '2026-01-01 10:00:00', '2026-01-01 11:00:00', 880001),
                (880002, 'new', 'normal', NULL, NULL, NULL, NULL, '2026-01-01 08:00:00', '2026-01-10 00:00:00', 'repo-read-p1', 880001, 880002, NULL, NULL, NULL),
                (880003, 'waiting', 'low', 880002, NULL, NULL, NULL, '2026-01-02 08:00:00', '2026-01-09 00:00:00', 'repo-read-p2', 880002, 880003, NULL, NULL, 880002),
                (880004, 'resolved', 'normal', 880001, '2026-01-03 08:10:00', '2026-01-03 09:00:00', NULL, '2026-01-03 08:00:00', '2026-01-08 00:00:00', 'repo-read-p2', 880002, 880004, NULL, NULL, 880001),
                (880005, 'closed', 'urgent', 880003, '2026-01-04 08:01:00', '2026-01-04 08:30:00', '2026-01-04 09:00:00', '2026-01-04 08:00:00', '2026-01-07 00:00:00', NULL, 880003, 880005, NULL, NULL, NULL),
                (880006, 'new', 'normal', 880001, NULL, NULL, NULL, '2025-12-31 23:00:00', '2026-01-11 00:00:00', 'repo-read-p1', 880003, 880006, NULL, NULL, 880002)",
            "INSERT INTO messages (id, direction, content, media, created_at, conversation_id, sender_agent_id, sender_type) VALUES
                (880001, 'entrante', 'hola', NULL, '2026-01-01 09:00:00', 880001, NULL, NULL),
                (880002, 'saliente', 'buenas', NULL, '2026-01-01 10:00:00', 880001, 880001, 'agente'),
                (880003, 'entrante', 'tengo un problema', NULL, '2026-01-01 10:00:00', 880001, NULL, NULL),
                (880004, 'entrante', 'sigue', NULL, '2026-01-01 10:30:00', 880001, NULL, NULL),
                (880005, 'saliente', 'auto', NULL, '2026-01-01 11:00:00', 880001, NULL, 'automatico'),
                (880006, 'entrante', 'otra', NULL, '2026-01-02 08:00:00', 880003, NULL, NULL)",
            "INSERT INTO tags (id, name, created_at) VALUES (880001, 'repo-read-vip', '2026-01-01 00:00:00'), (880002, 'repo-read-urgente', '2026-01-01 00:00:00'), (880003, 'repo-read-otro', '2026-01-01 00:00:00')",
            "INSERT INTO ticket_tags (id, created_at, ticket_id, tag_id) VALUES
                (880001, '2026-01-01 00:00:00', 880001, 880001), (880002, '2026-01-01 00:00:00', 880001, 880002),
                (880004, '2026-01-01 00:00:00', 880003, 880003), (880003, '2026-01-01 00:00:00', 880003, 880001),
                (880005, '2026-01-01 00:00:00', 880004, 880002)",
            "INSERT INTO ticket_events (id, ticket_id, type, source, created_at) VALUES
                (880001, 880001, 'window_expired_closed', 'system', '2026-01-01 13:00:00'),
                (880002, 880001, 'manual_resolved_owner', 'u:880001', '2026-01-01 15:00:00'),
                (880003, 880001, 'tag_added', 'u:880001', '2026-01-01 15:30:00'),
                (880004, 880002, 'manual_closed_override', 'u:880002', '2026-01-01 11:30:00'),
                (880005, 880002, 'manual_closed_unowned', 'u:880003', '2026-01-01 11:40:00')",
            "INSERT INTO assignments (id, type, assigned_at, assigned_by, notes, ticket_id, agent_id, previous_agent_id) VALUES
                (880001, 'ASSIGN', '2026-01-01 09:01:00', 880001, NULL, 880001, 880001, NULL),
                (880002, 'REASSIGN', '2026-01-01 12:30:00', 880001, NULL, 880001, 880002, 880001)",
            "INSERT INTO outbox_events (id, aggregate_type, aggregate_id, topic, event_type, payload, created_at, retry_count, status, sequence) VALUES
                (880001, 'Ticket', 880001, 't', 'ticket.creado', '{}', '2026-01-01 09:00:00', 0, 'published', 1),
                (880002, 'Ticket', 880001, 't', 'ticket.actualizado', '{}', '2026-01-01 11:20:00', 0, 'published', 2),
                (880003, 'Message', 880001, 't', 'ticket.creado', '{}', '2026-01-01 08:00:00', 0, 'published', 3)",
        ] as $sql) {
            $this->conn->executeStatement($sql);
        }
    }

    private function rollBackFixture(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
    }
}
