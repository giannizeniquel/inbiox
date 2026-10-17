<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Query;

use App\Ticket\Query\FiltrosDeTicketBuilder;
use App\Ticket\Repository\FiltrosDeTicket;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for `FiltrosDeTicketBuilder::build()` — no DB, no kernel.
 *
 * Since phase 3c-2 it returns normalized `FiltrosDeTicket` and holds no SQL;
 * `TicketRepositoryReadTest` covers the WHERE clauses built from them.
 */
class TicketFilterBuilderTest extends TestCase
{
    private FiltrosDeTicketBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FiltrosDeTicketBuilder();
    }

    private static function assertNoFilters(FiltrosDeTicket $f): void
    {
        self::assertNull($f->assignment);
        self::assertNull($f->mineAgentId);
        self::assertNull($f->scopeAgentId);
        self::assertNull($f->phoneNumberId);
        self::assertSame([], $f->statuses);
        self::assertSame([], $f->tags);
        self::assertNull($f->teamId);
    }

    public function test_no_filters(): void
    {
        self::assertNoFilters($this->builder->build());
        self::assertNoFilters($this->builder->build(filter: 'all'));
    }

    public function test_unassigned_and_assigned_filters(): void
    {
        self::assertSame('unassigned', $this->builder->build(filter: 'unassigned')->assignment);
        self::assertSame('assigned', $this->builder->build(filter: 'assigned')->assignment);
    }

    public function test_mine_filter_with_agent_id(): void
    {
        $f = $this->builder->build(filter: 'mine', agentId: '7');

        self::assertSame('7', $f->mineAgentId);
        self::assertNull($f->assignment);
    }

    public function test_mine_filter_without_a_truthy_agent_id_adds_nothing(): void
    {
        self::assertNoFilters($this->builder->build(filter: 'mine', agentId: null));
        self::assertNoFilters($this->builder->build(filter: 'mine', agentId: '0'));
    }

    public function test_agent_id_is_ignored_without_the_mine_filter(): void
    {
        self::assertNoFilters($this->builder->build(filter: 'all', agentId: '7'));
    }

    public function test_ownership_scope_is_kept_as_a_string(): void
    {
        self::assertSame('42', $this->builder->build(scopeAgentId: 42)->scopeAgentId);
    }

    public function test_workspace_scope(): void
    {
        self::assertSame('phone-1', $this->builder->build(phoneNumberId: 'phone-1')->phoneNumberId);
    }

    public function test_status_whitelist_keeps_only_known_statuses_trimmed(): void
    {
        self::assertSame(['new', 'active'], $this->builder->build(status: 'new, bogus ,active ')->statuses);
    }

    public function test_status_all_unknown_or_empty_adds_nothing(): void
    {
        self::assertSame([], $this->builder->build(status: 'bogus,nope')->statuses);
        self::assertSame([], $this->builder->build(status: '')->statuses);
        self::assertSame([], $this->builder->build(status: '0')->statuses);
    }

    public function test_tags_are_normalized(): void
    {
        self::assertSame(['vip'], $this->builder->build(tag: 'VIP')->tags);
        self::assertSame(['vip', 'muy urgente'], $this->builder->build(tag: ' vip , Muy   Urgente ')->tags);
    }

    public function test_blank_tag_segments_are_discarded(): void
    {
        self::assertSame(['vip', 'urgente'], $this->builder->build(tag: 'vip,,urgente')->tags);
    }

    public function test_duplicate_tags_are_deduplicated(): void
    {
        self::assertSame(['vip'], $this->builder->build(tag: 'vip,vip')->tags);
    }

    public function test_empty_blank_or_null_tag_applies_no_filter(): void
    {
        self::assertSame([], $this->builder->build(tag: '')->tags);
        self::assertSame([], $this->builder->build(tag: ' , ')->tags);
        self::assertSame([], $this->builder->build(tag: null)->tags);
    }

    public function test_more_than_five_tags_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A maximum of 5 tags may be filtered at once');

        $this->builder->build(tag: 'a,b,c,d,e,f');
    }

    public function test_exactly_five_tags_does_not_throw(): void
    {
        self::assertCount(5, $this->builder->build(tag: 'a,b,c,d,e')->tags);
    }

    public function test_six_tags_that_deduplicate_to_five_do_not_throw(): void
    {
        self::assertCount(5, $this->builder->build(tag: 'a,b,c,d,e,a')->tags);
    }

    public function test_team_id_is_kept_as_a_string(): void
    {
        self::assertNull($this->builder->build(teamId: null)->teamId);
        self::assertSame('7', $this->builder->build(teamId: 7)->teamId);
    }

    public function test_all_filters_together(): void
    {
        $f = $this->builder->build(
            filter: 'mine',
            agentId: '9',
            scopeAgentId: 42,
            phoneNumberId: 'phone-1',
            status: 'active,waiting',
            tag: 'vip,urgente',
            teamId: 3,
        );

        self::assertNull($f->assignment);
        self::assertSame('9', $f->mineAgentId);
        self::assertSame('42', $f->scopeAgentId);
        self::assertSame('phone-1', $f->phoneNumberId);
        self::assertSame(['active', 'waiting'], $f->statuses);
        self::assertSame(['vip', 'urgente'], $f->tags);
        self::assertSame('3', $f->teamId);
    }
}
