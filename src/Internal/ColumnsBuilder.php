<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use TinyBlocks\Outbox\Schema\Columns;
use TinyBlocks\Outbox\Schema\IdentityColumnType;

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

    public static function create(): ColumnsBuilder
    {
        return new ColumnsBuilder();
    }

    public function withPayload(string $name): ColumnsBuilder
    {
        $this->payload = $name;
        return $this;
    }

    public function withRevision(string $name): ColumnsBuilder
    {
        $this->revision = $name;
        return $this;
    }

    public function withCreatedAt(string $name): ColumnsBuilder
    {
        $this->createdAt = $name;
        return $this;
    }

    public function withEventType(string $name): ColumnsBuilder
    {
        $this->eventType = $name;
        return $this;
    }

    public function withOccurredAt(string $name): ColumnsBuilder
    {
        $this->occurredAt = $name;
        return $this;
    }

    public function withAggregateType(string $name): ColumnsBuilder
    {
        $this->aggregateType = $name;
        return $this;
    }

    public function withAggregateVersion(string $name): ColumnsBuilder
    {
        $this->aggregateVersion = $name;
        return $this;
    }

    public function withId(string $name, IdentityColumnType $type): ColumnsBuilder
    {
        $this->idName = $name;
        $this->idType = $type;
        return $this;
    }

    public function withAggregateId(string $name, IdentityColumnType $type): ColumnsBuilder
    {
        $this->aggregateIdName = $name;
        $this->aggregateIdType = $type;
        return $this;
    }

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
}
