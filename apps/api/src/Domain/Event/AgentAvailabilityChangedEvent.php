<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Marker domain event for agent availability changes.
 *
 * FQCN used by EventTypeMapper to map to RealtimeEventType::AGENT_AVAILABILITY_CHANGED.
 * Not collected via EventCollector — OutboxEvent is created directly.
 */
final class AgentAvailabilityChangedEvent
{
}
