<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Mocks;

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Outbox\Serialization\SerializedSnapshot;
use TinyBlocks\Outbox\Serialization\SnapshotSerializer;

final readonly class CustomOrderSnapshotSerializer implements SnapshotSerializer
{
    public function supports(EventRecord $record): bool
    {
        return true;
    }

    public function serialize(EventRecord $record): SerializedSnapshot
    {
        return SerializedSnapshot::from(snapshot: '{"custom":true}');
    }
}
