<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Unit;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventTranslators;
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

    public function __construct(
        private readonly IntegrationEventTranslators $translators,
        private readonly PayloadSerializers $payloadSerializers
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
        $this->aggregateVersions = [];
    }

    public function push(EventRecords $records): void
    {
        if (!$this->transactionActive) {
            throw OutboxRequiresActiveTransaction::asMissing();
        }

        $records->each(actions: function (EventRecord $eventRecord): void {
            $translator = $this->translators->findFor(record: $eventRecord);

            if (is_null($translator)) {
                return;
            }

            $integrationEventRecord = IntegrationEventRecord::from(
                eventRecord: $eventRecord,
                integrationEvent: $translator->translate(record: $eventRecord)
            );

            $payloadSerializer = $this->payloadSerializers->findFor(record: $integrationEventRecord);

            if (is_null($payloadSerializer)) {
                throw PayloadSerializerNotConfigured::forEventClass(
                    eventClass: $integrationEventRecord->event::class
                );
            }

            $payloadSerializer->serialize(record: $integrationEventRecord);

            $aggregateKey = sprintf(
                '%s|%s|%d',
                $integrationEventRecord->aggregateType,
                $integrationEventRecord->aggregateId->identityValue(),
                $integrationEventRecord->aggregateVersion->value
            );

            if (isset($this->aggregateVersions[$aggregateKey])) {
                throw DuplicateAggregateVersion::forRecord(
                    previous: null,
                    aggregateId: $integrationEventRecord->aggregateId->identityValue(),
                    aggregateType: $integrationEventRecord->aggregateType,
                    aggregateVersion: $integrationEventRecord->aggregateVersion->value
                );
            }

            $eventId = (string)$integrationEventRecord->id;

            if (isset($this->records[$eventId])) {
                throw DuplicateOutboxEvent::forRecord(
                    eventId: $integrationEventRecord->id,
                    previous: new UniqueConstraintViolationException(
                        new DriverExceptionStub('Duplicate entry for key PRIMARY'),
                        null
                    )
                );
            }

            $this->aggregateVersions[$aggregateKey] = true;
            $this->records[$eventId] = $integrationEventRecord;
        });
    }
}
