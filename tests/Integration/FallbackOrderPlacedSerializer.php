<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Integration;

use Test\TinyBlocks\Outbox\Models\OrderShipped;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;
use TinyBlocks\Outbox\Serialization\PayloadSerializer;
use TinyBlocks\Outbox\Serialization\SerializedPayload;

final readonly class FallbackOrderPlacedSerializer implements PayloadSerializer
{
    public function supports(IntegrationEventRecord $record): bool
    {
        return $record->event instanceof OrderShipped;
    }

    public function serialize(IntegrationEventRecord $record): SerializedPayload
    {
        return SerializedPayload::from(payload: '{"fallback":true}');
    }
}
