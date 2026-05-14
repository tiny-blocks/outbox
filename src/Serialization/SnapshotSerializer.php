<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\EventRecord;

interface SnapshotSerializer
{
    /**
     * Whether this serializer handles the snapshot in the given record.
     *
     * @param EventRecord $record The record being serialized.
     * @return bool True if this serializer can produce the snapshot for the record.
     */
    public function supports(EventRecord $record): bool;

    /**
     * Produces the persistent snapshot for the aggregate state in the record.
     *
     * @param EventRecord $record The record being serialized.
     * @return SerializedSnapshot The serialized snapshot.
     */
    public function serialize(EventRecord $record): SerializedSnapshot;
}
