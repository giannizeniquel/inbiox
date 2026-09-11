<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Query;

use App\Application\Query\TicketFilterBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for `TicketFilterBuilder::build()` — no DB, no kernel.
 *
 * Locks the clause/param ORDER that `ListTicketsQuery` depends on for its
 * positional `?` placeholders: filter/scope → workspace scope → status → tag.
 */
class TicketFilterBuilderTest extends TestCase
{
    private TicketFilterBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TicketFilterBuilder();
    }

    public function test_no_filters_produces_empty_where_and_params(): void
    {
        [$where, $params] = $this->builder->build();

        self::assertSame([], $where);
        self::assertSame([], $params);
    }

    public function test_unassigned_filter(): void
    {
        [$where, $params] = $this->builder->build(filter: 'unassigned');

        self::assertSame(['t.assigned_agent_id IS NULL'], $where);
        self::assertSame([], $params);
    }

    public function test_assigned_filter(): void
    {
        [$where, $params] = $this->builder->build(filter: 'assigned');

        self::assertSame(['t.assigned_agent_id IS NOT NULL'], $where);
        self::assertSame([], $params);
    }

    public function test_mine_filter_with_agent_id(): void
    {
        [$where, $params] = $this->builder->build(filter: 'mine', agentId: '7');

        self::assertSame(['t.assigned_agent_id = ?'], $where);
        self::assertSame(['7'], $params);
    }

    public function test_mine_filter_without_agent_id_adds_no_clause(): void
    {
        [$where, $params] = $this->builder->build(filter: 'mine', agentId: null);

        self::assertSame([], $where);
        self::assertSame([], $params);
    }

    public function test_ownership_scope_clause_and_param_order(): void
    {
        [$where, $params] = $this->builder->build(scopeAgentId: 42);

        self::assertSame(['t.assigned_agent_id = ?'], $where);
        self::assertSame(['42'], $params);
    }

    public function test_workspace_scope_clause_and_param_order(): void
    {
        [$where, $params] = $this->builder->build(phoneNumberId: 'phone-1');

        self::assertSame(['t.phone_number_id = ?'], $where);
        self::assertSame(['phone-1'], $params);
    }

    public function test_status_whitelist_keeps_only_known_statuses(): void
    {
        [$where, $params] = $this->builder->build(status: 'new,bogus,active');

        self::assertSame(['t.status IN (?,?)'], $where);
        self::assertSame(['new', 'active'], $params);
    }

    public function test_status_all_unknown_produces_no_clause(): void
    {
        [$where, $params] = $this->builder->build(status: 'bogus,nope');

        self::assertSame([], $where);
        self::assertSame([], $params);
    }

    public function test_single_tag_produces_exists_clause(): void
    {
        [$where, $params] = $this->builder->build(tag: 'VIP');

        self::assertCount(1, $where);
        self::assertStringContainsString('EXISTS (SELECT 1 FROM ticket_tags tt JOIN tags tg', $where[0]);
        self::assertSame(['vip'], $params);
    }

    public function test_multi_tag_and_semantics_produces_one_exists_per_tag(): void
    {
        [$where, $params] = $this->builder->build(tag: 'vip,urgente');

        self::assertCount(2, $where);
        self::assertSame(['vip', 'urgente'], $params);
    }

    public function test_blank_tag_segments_are_discarded(): void
    {
        [$where, $params] = $this->builder->build(tag: 'vip,,urgente');

        self::assertCount(2, $where);
        self::assertSame(['vip', 'urgente'], $params);
    }

    public function test_duplicate_tags_are_deduplicated(): void
    {
        [$where, $params] = $this->builder->build(tag: 'vip,vip');

        self::assertCount(1, $where);
        self::assertSame(['vip'], $params);
    }

    public function test_empty_or_null_tag_applies_no_filter(): void
    {
        [$whereEmpty, $paramsEmpty] = $this->builder->build(tag: '');
        [$whereNull, $paramsNull] = $this->builder->build(tag: null);

        self::assertSame([], $whereEmpty);
        self::assertSame([], $paramsEmpty);
        self::assertSame([], $whereNull);
        self::assertSame([], $paramsNull);
    }

    public function test_more_than_five_tags_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->build(tag: 'a,b,c,d,e,f');
    }

    public function test_exactly_five_tags_does_not_throw(): void
    {
        [$where, $params] = $this->builder->build(tag: 'a,b,c,d,e');

        self::assertCount(5, $where);
        self::assertCount(5, $params);
    }

    public function test_clause_and_param_order_across_all_filters(): void
    {
        [$where, $params] = $this->builder->build(
            scopeAgentId: 42,
            phoneNumberId: 'phone-1',
            status: 'active,waiting',
            tag: 'vip',
        );

        // Order: scope → workspace → status → tag (matches ListTicketsQuery's
        // original block order; positional `?` params depend on this).
        self::assertSame(
            [
                't.assigned_agent_id = ?',
                't.phone_number_id = ?',
                't.status IN (?,?)',
                'EXISTS (SELECT 1 FROM ticket_tags tt JOIN tags tg ON tg.id = tt.tag_id
                                    WHERE tt.ticket_id = t.id AND tg.name = ?)',
            ],
            $where,
        );
        self::assertSame(['42', 'phone-1', 'active', 'waiting', 'vip'], $params);
    }
}
