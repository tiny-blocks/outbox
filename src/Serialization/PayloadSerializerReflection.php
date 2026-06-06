<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;
use TinyBlocks\Mapper\Mapper;
use TinyBlocks\Mapper\Serializer;

final readonly class PayloadSerializerReflection implements PayloadSerializer
{
    private Serializer $serializer;

    public function __construct()
    {
        $this->serializer = Mapper::create();
    }

    public function supports(IntegrationEventRecord $record): bool
    {
        return true;
    }

    public function serialize(IntegrationEventRecord $record): SerializedPayload
    {
        return SerializedPayload::fromArray(payload: $this->serializer->toArray(source: $record->event));
    }
}
