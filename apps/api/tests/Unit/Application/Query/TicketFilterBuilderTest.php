<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Query;

use App\Ticket\FiltroDeBandeja;
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
        self::assertNull($f->asignacion);
        self::assertNull($f->misAgenteId);
        self::assertNull($f->scopeAgenteId);
        self::assertNull($f->phoneNumberId);
        self::assertSame([], $f->estados);
        self::assertSame([], $f->etiquetas);
        self::assertNull($f->equipoId);
    }

    public function test_no_filters(): void
    {
        self::assertNoFilters($this->builder->build());
        self::assertNoFilters($this->builder->build(filtro: 'todos'));
    }

    public function test_unassigned_and_assigned_filters(): void
    {
        self::assertSame(FiltroDeBandeja::SIN_ASIGNAR, $this->builder->build(filtro: 'sin_asignar')->asignacion);
        self::assertSame(FiltroDeBandeja::ASIGNADOS, $this->builder->build(filtro: 'asignados')->asignacion);
    }

    public function test_the_old_english_filter_values_add_no_filter(): void
    {
        foreach (['all', 'mine', 'assigned', 'unassigned'] as $viejo) {
            self::assertNoFilters($this->builder->build(filtro: $viejo, agenteId: '7'));
        }
    }

    public function test_mine_filter_with_agent_id(): void
    {
        $f = $this->builder->build(filtro: 'mios', agenteId: '7');

        self::assertSame('7', $f->misAgenteId);
        self::assertNull($f->asignacion);
    }

    public function test_mine_filter_without_a_truthy_agent_id_adds_nothing(): void
    {
        self::assertNoFilters($this->builder->build(filtro: 'mios', agenteId: null));
        self::assertNoFilters($this->builder->build(filtro: 'mios', agenteId: '0'));
    }

    public function test_agent_id_is_ignored_without_the_mine_filter(): void
    {
        self::assertNoFilters($this->builder->build(filtro: 'todos', agenteId: '7'));
    }

    public function test_ownership_scope_is_kept_as_a_string(): void
    {
        self::assertSame('42', $this->builder->build(scopeAgenteId: 42)->scopeAgenteId);
    }

    public function test_workspace_scope(): void
    {
        self::assertSame('phone-1', $this->builder->build(phoneNumberId: 'phone-1')->phoneNumberId);
    }

    public function test_status_whitelist_keeps_only_known_statuses_trimmed(): void
    {
        self::assertSame(['nuevo', 'activo'], $this->builder->build(estado: 'nuevo, bogus ,activo ')->estados);
    }

    public function test_status_all_unknown_or_empty_adds_nothing(): void
    {
        self::assertSame([], $this->builder->build(estado: 'bogus,nope')->estados);
        self::assertSame([], $this->builder->build(estado: '')->estados);
        self::assertSame([], $this->builder->build(estado: '0')->estados);
    }

    public function test_tags_are_normalized(): void
    {
        self::assertSame(['vip'], $this->builder->build(etiqueta: 'VIP')->etiquetas);
        self::assertSame(['vip', 'muy urgente'], $this->builder->build(etiqueta: ' vip , Muy   Urgente ')->etiquetas);
    }

    public function test_blank_tag_segments_are_discarded(): void
    {
        self::assertSame(['vip', 'urgente'], $this->builder->build(etiqueta: 'vip,,urgente')->etiquetas);
    }

    public function test_duplicate_tags_are_deduplicated(): void
    {
        self::assertSame(['vip'], $this->builder->build(etiqueta: 'vip,vip')->etiquetas);
    }

    public function test_empty_blank_or_null_tag_applies_no_filter(): void
    {
        self::assertSame([], $this->builder->build(etiqueta: '')->etiquetas);
        self::assertSame([], $this->builder->build(etiqueta: ' , ')->etiquetas);
        self::assertSame([], $this->builder->build(etiqueta: null)->etiquetas);
    }

    public function test_more_than_five_tags_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A maximum of 5 tags may be filtered at once');

        $this->builder->build(etiqueta: 'a,b,c,d,e,f');
    }

    public function test_exactly_five_tags_does_not_throw(): void
    {
        self::assertCount(5, $this->builder->build(etiqueta: 'a,b,c,d,e')->etiquetas);
    }

    public function test_six_tags_that_deduplicate_to_five_do_not_throw(): void
    {
        self::assertCount(5, $this->builder->build(etiqueta: 'a,b,c,d,e,a')->etiquetas);
    }

    public function test_team_id_is_kept_as_a_string(): void
    {
        self::assertNull($this->builder->build(equipoId: null)->equipoId);
        self::assertSame('7', $this->builder->build(equipoId: 7)->equipoId);
    }

    public function test_all_filters_together(): void
    {
        $f = $this->builder->build(
            filtro: 'mios',
            agenteId: '9',
            scopeAgenteId: 42,
            phoneNumberId: 'phone-1',
            estado: 'activo,en_espera',
            etiqueta: 'vip,urgente',
            equipoId: 3,
        );

        self::assertNull($f->asignacion);
        self::assertSame('9', $f->misAgenteId);
        self::assertSame('42', $f->scopeAgenteId);
        self::assertSame('phone-1', $f->phoneNumberId);
        self::assertSame(['activo', 'en_espera'], $f->estados);
        self::assertSame(['vip', 'urgente'], $f->etiquetas);
        self::assertSame('3', $f->equipoId);
    }
}
