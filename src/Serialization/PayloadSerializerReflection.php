<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\EventRecord;

final readonly class PayloadSerializerReflection implements PayloadSerializer
{
    public function supports(EventRecord $record): bool
    {
        return true;
    }

    public function serialize(EventRecord $record): SerializedPayload
    {
        return SerializedPayload::fromArray(payload: get_object_vars($record->event));
    }
}
