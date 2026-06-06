<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;
use TinyBlocks\Collection\Collection;

/**
 * Ordered collection of {@see PayloadSerializer} instances.
 *
 * <p>Lookup follows first-match-wins semantics: {@see PayloadSerializers::findFor} returns the first
 * serializer whose {@see PayloadSerializer::supports} returns <code>true</code> for the given record,
 * or <code>null</code> when no serializer handles it.</p>
 *
 * @extends Collection<PayloadSerializer>
 */
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
