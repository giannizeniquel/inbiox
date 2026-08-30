<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\ClosureAuditClassifier;
use App\Domain\Event\TicketEventType;
use App\Domain\User\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `ClosureAuditClassifier` (design.md D2).
 *
 * Pure, dependency-free classifier: no Doctrine, no HTTP.
 */
class ClosureAuditClassifierTest extends TestCase
{
    private ClosureAuditClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ClosureAuditClassifier();
    }

    /**
     * @dataProvider classifyProvider
     */
    public function testClassify(string $newStatus, ?int $previousOwnerId, ?int $actingAgentId, string $expected): void
    {
        self::assertSame(
            $expected,
            $this->classifier->classify($newStatus, $previousOwnerId, $actingAgentId)
        );
    }

    /**
     * @return array<string, array{string, ?int, ?int, string}>
     */
    public static function classifyProvider(): array
    {
        return [
            // resolved — unowned: (null, any) → UNOWNED
            'resolved unowned, acting agent set'    => ['resolved', null, 5, TicketEventType::MANUAL_RESOLVED_UNOWNED],
            'resolved unowned, no acting agent'     => ['resolved', null, null, TicketEventType::MANUAL_RESOLVED_UNOWNED],
            // resolved — owner: (7, 7) → OWNER
            'resolved owner'                        => ['resolved', 7, 7, TicketEventType::MANUAL_RESOLVED_OWNER],
            // resolved — override: (7, 3|null) → OVERRIDE
            'resolved override, different agent'    => ['resolved', 7, 3, TicketEventType::MANUAL_RESOLVED_OVERRIDE],
            'resolved override, no acting agent'    => ['resolved', 7, null, TicketEventType::MANUAL_RESOLVED_OVERRIDE],

            // closed — unowned: (null, any) → UNOWNED
            'closed unowned, acting agent set'      => ['closed', null, 5, TicketEventType::MANUAL_CLOSED_UNOWNED],
            'closed unowned, no acting agent'       => ['closed', null, null, TicketEventType::MANUAL_CLOSED_UNOWNED],
            // closed — owner: (7, 7) → OWNER
            'closed owner'                          => ['closed', 7, 7, TicketEventType::MANUAL_CLOSED_OWNER],
            // closed — override: (7, 3|null) → OVERRIDE
            'closed override, different agent'      => ['closed', 7, 3, TicketEventType::MANUAL_CLOSED_OVERRIDE],
            'closed override, no acting agent'      => ['closed', 7, null, TicketEventType::MANUAL_CLOSED_OVERRIDE],
        ];
    }

    public function testActorReferenceReturnsNamespacedUserId(): void
    {
        $user = new User();
        $prop = new \ReflectionProperty($user, 'id');
        $prop->setAccessible(true);
        $prop->setValue($user, 9);

        self::assertSame('u:9', $this->classifier->actorReference($user));
    }

    /**
     * Guards the `ticket_events.type` VARCHAR(50) contract (design.md D2).
     */
    public function testEveryConstantFitsTheColumnWidth(): void
    {
        $constants = [
            TicketEventType::MANUAL_RESOLVED_OWNER,
            TicketEventType::MANUAL_RESOLVED_OVERRIDE,
            TicketEventType::MANUAL_RESOLVED_UNOWNED,
            TicketEventType::MANUAL_CLOSED_OWNER,
            TicketEventType::MANUAL_CLOSED_OVERRIDE,
            TicketEventType::MANUAL_CLOSED_UNOWNED,
        ];

        foreach ($constants as $constant) {
            self::assertLessThanOrEqual(50, \strlen($constant), \sprintf('Constant "%s" exceeds VARCHAR(50)', $constant));
        }
    }
}
