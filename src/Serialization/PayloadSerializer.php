<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;

interface PayloadSerializer
{
    /**
     * Tells whether this serializer handles the integration event in the given record.
     *
     * @param IntegrationEventRecord $record The record being serialized.
     * @return bool True if this serializer can produce the payload for the integration event.
     */
    public function supports(IntegrationEventRecord $record): bool;

    /**
     * Produces the persistent payload for the integration event in the record.
     *
     * @param IntegrationEventRecord $record The record being serialized.
     * @return SerializedPayload The serialized payload.
     */
    public function serialize(IntegrationEventRecord $record): SerializedPayload;
}
