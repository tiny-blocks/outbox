<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Collection\Collection;

final class SnapshotSerializers extends Collection
{
    public function findFor(EventRecord $record): ?SnapshotSerializer
    {
        $serializer = $this->findBy(
            predicates: static fn(SnapshotSerializer $serializer): bool => $serializer->supports(record: $record)
        );

        return $serializer instanceof SnapshotSerializer ? $serializer : null;
    }
}
