<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;

final readonly class PayloadSerializerReflection implements PayloadSerializer
{
    public function supports(IntegrationEventRecord $record): bool
    {
        return true;
    }

    public function serialize(IntegrationEventRecord $record): SerializedPayload
    {
        return SerializedPayload::fromArray(payload: get_object_vars($record->event));
    }
}
