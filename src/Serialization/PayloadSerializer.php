<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\EventRecord;

interface PayloadSerializer
{
    /**
     * Tells whether this serializer handles the event in the given record.
     *
     * @param EventRecord $record The record being serialized.
     * @return bool True if this serializer can produce the payload for the event.
     */
    public function supports(EventRecord $record): bool;

    /**
     * Produces the persistent payload for the event in the record.
     *
     * @param EventRecord $record The record being serialized.
     * @return SerializedPayload The serialized payload.
     */
    public function serialize(EventRecord $record): SerializedPayload;
}
