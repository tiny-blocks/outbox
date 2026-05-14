<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Mocks;

use Test\TinyBlocks\Outbox\Models\RefundIssued;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Outbox\Serialization\PayloadSerializer;
use TinyBlocks\Outbox\Serialization\SerializedPayload;

final readonly class RefundIssuedSerializer implements PayloadSerializer
{
    public function supports(EventRecord $record): bool
    {
        return $record->event instanceof RefundIssued;
    }

    public function serialize(EventRecord $record): SerializedPayload
    {
        return SerializedPayload::from(payload: '{"type":"refund"}');
    }
}
