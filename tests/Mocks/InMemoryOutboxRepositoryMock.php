<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Mocks;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateSequence;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\Exceptions\SnapshotSerializerNotConfigured;
use TinyBlocks\Outbox\OutboxRepository;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;
use TinyBlocks\Outbox\Serialization\SnapshotSerializers;

final class InMemoryOutboxRepositoryMock implements OutboxRepository
{
    private bool $transactionActive = false;
    private array $records = [];
    private array $aggregateSequences = [];

    public function __construct(
        private readonly PayloadSerializers $payloadSerializers,
        private readonly SnapshotSerializers $snapshotSerializers
    ) {
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
        $this->aggregateSequences = [];
    }

    public function push(EventRecords $records): void
    {
        if (!$this->transactionActive) {
            throw OutboxRequiresActiveTransaction::asMissing();
        }

        $records->each(actions: function (EventRecord $record): void {
            $payloadSerializer = $this->payloadSerializers->findFor(record: $record);

            if (is_null($payloadSerializer)) {
                throw new PayloadSerializerNotConfigured(eventClass: $record->event::class);
            }

            $snapshotSerializer = $this->snapshotSerializers->findFor(record: $record);

            if (is_null($snapshotSerializer)) {
                throw SnapshotSerializerNotConfigured::for(aggregateType: $record->aggregateType);
            }

            $payloadSerializer->serialize(record: $record);
            $snapshotSerializer->serialize(record: $record);

            $aggregateKey = sprintf(
                '%s|%s|%d',
                $record->aggregateType,
                $record->identity->identityValue(),
                $record->sequenceNumber->value
            );

            if (isset($this->aggregateSequences[$aggregateKey])) {
                throw new DuplicateAggregateSequence(
                    aggregateId: (string)$record->identity->identityValue(),
                    aggregateType: $record->aggregateType,
                    sequenceNumber: $record->sequenceNumber->value
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

            $this->aggregateSequences[$aggregateKey] = true;
            $this->records[$eventId] = $record;
        });
    }
}
