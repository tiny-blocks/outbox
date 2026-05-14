<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Collection\Collection;

final class PayloadSerializers extends Collection
{
    public function findFor(EventRecord $record): ?PayloadSerializer
    {
        $serializer = $this->findBy(
            predicates: static fn(PayloadSerializer $serializer): bool => $serializer->supports(record: $record)
        );

        return $serializer instanceof PayloadSerializer ? $serializer : null;
    }
}
