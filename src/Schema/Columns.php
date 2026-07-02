<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

final readonly class Columns
{
    private function __construct(
        public IdentityColumn $id,
        public string $payload,
        public string $revision,
        public string $createdAt,
        public string $eventType,
        public string $occurredAt,
        public IdentityColumn $aggregateId,
        public string $aggregateType,
        public string $aggregateVersion
    ) {
    }

    /**
     * Builds a Columns with the explicit column names for every outbox field.
     *
     * @param IdentityColumn $id The identity column for the event id.
     * @param string $payload The column name for the serialized event payload.
     * @param string $revision The column name for the event schema revision.
     * @param string $createdAt The column name for the row insertion timestamp.
     * @param string $eventType The column name for the event type identifier.
     * @param string $occurredAt The column name for the event occurrence timestamp.
     * @param IdentityColumn $aggregateId The identity column for the owning aggregate.
     * @param string $aggregateType The column name for the aggregate type identifier.
     * @param string $aggregateVersion The column name for the per-aggregate version counter.
     * @return Columns The built column configuration.
     */
    public static function from(
        IdentityColumn $id,
        string $payload,
        string $revision,
        string $createdAt,
        string $eventType,
        string $occurredAt,
        IdentityColumn $aggregateId,
        string $aggregateType,
        string $aggregateVersion
    ): Columns {
        return new Columns(
            id: $id,
            payload: $payload,
            revision: $revision,
            createdAt: $createdAt,
            eventType: $eventType,
            occurredAt: $occurredAt,
            aggregateId: $aggregateId,
            aggregateType: $aggregateType,
            aggregateVersion: $aggregateVersion
        );
    }

    /**
     * Creates a ColumnsBuilder used to customize the outbox column names.
     *
     * @return ColumnsBuilder A new builder seeded with the default column names.
     */
    public static function builder(): ColumnsBuilder
    {
        return ColumnsBuilder::create();
    }

    /**
     * Creates a Columns instance using the default outbox column names.
     *
     * @return Columns The default column configuration.
     */
    public static function default(): Columns
    {
        return ColumnsBuilder::create()->build();
    }
}
