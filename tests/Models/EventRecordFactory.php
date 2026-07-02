<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use Ramsey\Uuid\UuidInterface;
use TinyBlocks\BuildingBlocks\Aggregate\AggregateVersion;
use TinyBlocks\BuildingBlocks\Event\DomainEvent;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Utc;
use TinyBlocks\BuildingBlocks\Uuid;
use TinyBlocks\Time\Instant;

final readonly class EventRecordFactory
{
    private function __construct()
    {
    }

    public static function create(
        DomainEvent $event,
        string $aggregateType,
        ?UuidInterface $id = null,
        ?Instant $occurredAt = null,
        ?string $aggregateId = null,
        ?AggregateVersion $aggregateVersion = null
    ): EventRecord {
        return EventRecord::from(
            event: $event,
            aggregateId: new OrderId(value: ($aggregateId ?? Uuid::generateV7()->toString())),
            aggregateType: $aggregateType,
            aggregateVersion: ($aggregateVersion ?? AggregateVersion::first()),
            id: is_null($id) ? null : Uuid::from(value: $id->toString()),
            occurredAt: is_null($occurredAt) ? null : Utc::fromIso8601(value: $occurredAt->toIso8601())
        );
    }
}
