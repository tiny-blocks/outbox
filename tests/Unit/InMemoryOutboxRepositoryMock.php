<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Unit;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateVersion;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\OutboxRepository;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;

final class InMemoryOutboxRepositoryMock implements OutboxRepository
{
    private array $records = [];
    private bool $transactionActive = false;
    private array $aggregateVersions = [];

    public function __construct(private readonly PayloadSerializers $payloadSerializers)
    {
    }

    public function beginTransaction(): void
    {
        $this->transactionActive = true;
    }

    public function commit(): void
    {
        $this->transactionActive = false;
    }

    public function persistedRecords(): array
    {
        return $this->records;
    }

    public function rollback(): void
    {
        $this->transactionActive = false;
        $this->records = [];
        $this->aggregateVersions = [];
    }

    public function push(EventRecords $records): void
    {
        if (!$this->transactionActive) {
            throw OutboxRequiresActiveTransaction::asMissing();
        }

        $records->each(actions: function (EventRecord $record): void {
            $payloadSerializer = $this->payloadSerializers->findFor(record: $record);

            if (is_null($payloadSerializer)) {
                throw PayloadSerializerNotConfigured::forEventClass(eventClass: $record->event::class);
            }

            $payloadSerializer->serialize(record: $record);

            $aggregateKey = sprintf(
                '%s|%s|%d',
                $record->aggregateType,
                $record->aggregateId->identityValue(),
                $record->aggregateVersion->value
            );

            if (isset($this->aggregateVersions[$aggregateKey])) {
                throw DuplicateAggregateVersion::forRecord(
                    previous: null,
                    aggregateId: $record->aggregateId->identityValue(),
                    aggregateType: $record->aggregateType,
                    aggregateVersion: $record->aggregateVersion->value
                );
            }

            $eventId = (string)$record->id;

            if (isset($this->records[$eventId])) {
                throw DuplicateOutboxEvent::forRecord(
                    eventId: $record->id,
                    previous: new UniqueConstraintViolationException(
                        new DriverExceptionStub('Duplicate entry for key PRIMARY'),
                        null
                    )
                );
            }

            $this->aggregateVersions[$aggregateKey] = true;
            $this->records[$eventId] = $record;
        });
    }
}
