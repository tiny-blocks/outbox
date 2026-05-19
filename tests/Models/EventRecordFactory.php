<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use TinyBlocks\BuildingBlocks\Aggregate\AggregateVersion;
use TinyBlocks\BuildingBlocks\Event\DomainEvent;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventType;
use TinyBlocks\BuildingBlocks\Event\Revision;
use TinyBlocks\Time\Instant;

final readonly class EventRecordFactory
{
    private function __construct()
    {
    }

    public static function create(
        DomainEvent $event,
        string $aggregateType,
        string $eventTypeName,
        ?UuidInterface $id = null,
        ?Revision $revision = null,
        ?Instant $occurredAt = null,
        ?string $aggregateId = null,
        ?AggregateVersion $aggregateVersion = null
    ): EventRecord {
        return new EventRecord(
            id: $id ?? Uuid::uuid4(),
            event: $event,
            revision: $revision ?? Revision::initial(),
            eventType: EventType::fromString(value: $eventTypeName),
            occurredAt: $occurredAt ?? Instant::now(),
            aggregateId: new OrderId(value: $aggregateId ?? Uuid::uuid4()->toString()),
            aggregateType: $aggregateType,
            aggregateVersion: $aggregateVersion ?? AggregateVersion::first()
        );
    }
}
