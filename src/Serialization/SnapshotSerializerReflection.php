<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\EventRecord;

final readonly class SnapshotSerializerReflection implements SnapshotSerializer
{
    public function supports(EventRecord $record): bool
    {
        return true;
    }

    public function serialize(EventRecord $record): SerializedSnapshot
    {
        return SerializedSnapshot::fromArray(snapshot: $record->snapshotData->toArray());
    }
}
