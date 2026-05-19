<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Unit;

use Test\TinyBlocks\Outbox\Models\OrderPlaced;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Outbox\Serialization\PayloadSerializer;
use TinyBlocks\Outbox\Serialization\SerializedPayload;

final readonly class InvalidPayloadSerializer implements PayloadSerializer
{
    public function supports(EventRecord $record): bool
    {
        return $record->event instanceof OrderPlaced;
    }

    public function serialize(EventRecord $record): SerializedPayload
    {
        return SerializedPayload::from(payload: 'not json');
    }
}
