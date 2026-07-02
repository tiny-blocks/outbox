<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

/**
 * Fluent builder for the outbox column configuration.
 */
final class ColumnsBuilder
{
    private string $idName = 'id';
    private IdentityColumnType $idType = IdentityColumnType::BINARY;

    private string $payload = 'payload';
    private string $revision = 'revision';
    private string $createdAt = 'created_at';
    private string $eventType = 'event_type';
    private string $occurredAt = 'occurred_at';
    private string $aggregateIdName = 'aggregate_id';
    private IdentityColumnType $aggregateIdType = IdentityColumnType::BINARY;
    private string $aggregateType = 'aggregate_type';
    private string $aggregateVersion = 'aggregate_version';

    private function __construct()
    {
    }

    /**
     * Creates a ColumnsBuilder seeded with the default column names.
     *
     * @return ColumnsBuilder A new builder with the default column names.
     */
    public static function create(): ColumnsBuilder
    {
        return new ColumnsBuilder();
    }

    /**
     * Builds a Columns from the configured column names.
     *
     * @return Columns The built column configuration.
     */
    public function build(): Columns
    {
        return Columns::from(
            id: $this->idType->toColumn(name: $this->idName),
            payload: $this->payload,
            revision: $this->revision,
            createdAt: $this->createdAt,
            eventType: $this->eventType,
            occurredAt: $this->occurredAt,
            aggregateId: $this->aggregateIdType->toColumn(name: $this->aggregateIdName),
            aggregateType: $this->aggregateType,
            aggregateVersion: $this->aggregateVersion
        );
    }

    /**
     * Sets the event id column name and its identity storage type.
     *
     * @param string $name The event id column name.
     * @param IdentityColumnType $type The identity storage type.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withId(string $name, IdentityColumnType $type): ColumnsBuilder
    {
        $this->idName = $name;
        $this->idType = $type;
        return $this;
    }

    /**
     * Sets the event payload column name.
     *
     * @param string $name The payload column name.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withPayload(string $name): ColumnsBuilder
    {
        $this->payload = $name;
        return $this;
    }

    /**
     * Sets the schema revision column name.
     *
     * @param string $name The revision column name.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withRevision(string $name): ColumnsBuilder
    {
        $this->revision = $name;
        return $this;
    }

    /**
     * Sets the record creation timestamp column name.
     *
     * @param string $name The creation timestamp column name.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withCreatedAt(string $name): ColumnsBuilder
    {
        $this->createdAt = $name;
        return $this;
    }

    /**
     * Sets the event type column name.
     *
     * @param string $name The event type column name.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withEventType(string $name): ColumnsBuilder
    {
        $this->eventType = $name;
        return $this;
    }

    /**
     * Sets the event occurrence timestamp column name.
     *
     * @param string $name The occurrence timestamp column name.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withOccurredAt(string $name): ColumnsBuilder
    {
        $this->occurredAt = $name;
        return $this;
    }

    /**
     * Sets the aggregate id column name and its identity storage type.
     *
     * @param string $name The aggregate id column name.
     * @param IdentityColumnType $type The identity storage type.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withAggregateId(string $name, IdentityColumnType $type): ColumnsBuilder
    {
        $this->aggregateIdName = $name;
        $this->aggregateIdType = $type;
        return $this;
    }

    /**
     * Sets the aggregate type column name.
     *
     * @param string $name The aggregate type column name.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withAggregateType(string $name): ColumnsBuilder
    {
        $this->aggregateType = $name;
        return $this;
    }

    /**
     * Sets the aggregate version column name.
     *
     * @param string $name The aggregate version column name.
     * @return ColumnsBuilder The builder for chaining.
     */
    public function withAggregateVersion(string $name): ColumnsBuilder
    {
        $this->aggregateVersion = $name;
        return $this;
    }
}
