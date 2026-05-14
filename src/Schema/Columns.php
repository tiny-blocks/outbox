<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

use TinyBlocks\Outbox\Internal\ColumnsBuilder;
use TinyBlocks\Outbox\Internal\IdentityColumn;

final readonly class Columns
{
    private function __construct(
        public IdentityColumn $id,
        public string $payload,
        public string $revision,
        public string $snapshot,
        public string $createdAt,
        public string $eventType,
        public string $occurredAt,
        public IdentityColumn $aggregateId,
        public string $aggregateType,
        public string $sequenceNumber
    ) {
    }

    public static function builder(): ColumnsBuilder
    {
        return ColumnsBuilder::create();
    }

    public static function default(): Columns
    {
        return ColumnsBuilder::create()->build();
    }

    public static function from(
        IdentityColumn $id,
        string $payload,
        string $revision,
        string $snapshot,
        string $createdAt,
        string $eventType,
        string $occurredAt,
        IdentityColumn $aggregateId,
        string $aggregateType,
        string $sequenceNumber
    ): Columns {
        return new Columns(
            id: $id,
            payload: $payload,
            revision: $revision,
            snapshot: $snapshot,
            createdAt: $createdAt,
            eventType: $eventType,
            occurredAt: $occurredAt,
            aggregateId: $aggregateId,
            aggregateType: $aggregateType,
            sequenceNumber: $sequenceNumber
        );
    }
}
