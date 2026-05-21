<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;
use TinyBlocks\Collection\Collection;

final class PayloadSerializers extends Collection
{
    /**
     * Returns the first payload serializer that supports the given record, or null when none matches.
     *
     * @param IntegrationEventRecord $record The record whose payload serializer is being resolved.
     * @return PayloadSerializer|null The matching serializer, or null when no element supports the record.
     */
    public function findFor(IntegrationEventRecord $record): ?PayloadSerializer
    {
        $serializer = $this->findBy(
            predicates: static fn(PayloadSerializer $serializer): bool => $serializer->supports(record: $record)
        );

        return $serializer instanceof PayloadSerializer ? $serializer : null;
    }
}
