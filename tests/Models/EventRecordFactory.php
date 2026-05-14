<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use TinyBlocks\BuildingBlocks\Event\DomainEvent;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventType;
use TinyBlocks\BuildingBlocks\Event\Revision;
use TinyBlocks\BuildingBlocks\Event\SequenceNumber;
use TinyBlocks\BuildingBlocks\Snapshot\SnapshotData;
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
        ?array $snapshot = null,
        ?Instant $occurredOn = null,
        ?string $aggregateId = null,
        ?SequenceNumber $sequenceNumber = null
    ): EventRecord {
        return new EventRecord(
            id: $id ?? Uuid::uuid4(),
            type: EventType::fromString(value: $eventTypeName),
            event: $event,
            identity: new OrderId(value: $aggregateId ?? Uuid::uuid4()->toString()),
            revision: $revision ?? Revision::initial(),
            occurredOn: $occurredOn ?? Instant::now(),
            snapshotData: new SnapshotData(payload: $snapshot ?? []),
            aggregateType: $aggregateType,
            sequenceNumber: $sequenceNumber ?? SequenceNumber::first()
        );
    }
}
