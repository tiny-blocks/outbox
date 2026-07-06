<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventTranslators;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateVersion;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;
use TinyBlocks\Outbox\Serialization\SerializedPayload;

final readonly class OutboxWriter
{
    public function __construct(
        private Connection $connection,
        private PayloadSerializers $serializers,
        private TableLayout $tableLayout,
        private IntegrationEventTranslators $translators
    ) {
    }

    public function write(EventRecord $eventRecord): void
    {
        $translator = $this->translators->findFor(record: $eventRecord);

        try {
            if (is_null($translator)) {
                $insert = OutboxInsert::from(
                    record: $eventRecord,
                    payload: SerializedPayload::fromEvent(event: $eventRecord->event),
                    tableLayout: $this->tableLayout
                );

                $this->connection->executeStatement(sql: $insert->sql, params: $insert->parameters);

                return;
            }

            $record = IntegrationEventRecord::from(
                eventRecord: $eventRecord,
                integrationEvent: $translator->translate(record: $eventRecord)
            );

            $payloadSerializer = $this->serializers->findFor(record: $record);

            if (is_null($payloadSerializer)) {
                throw PayloadSerializerNotConfigured::forEventClass(eventClass: $record->event::class);
            }

            $insert = OutboxInsert::from(
                record: $record,
                payload: $payloadSerializer->serialize(record: $record),
                tableLayout: $this->tableLayout
            );

            $this->connection->executeStatement(sql: $insert->sql, params: $insert->parameters);
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->tableLayout->uniqueConstraint->isViolatedBy(exception: $exception)) {
                throw DuplicateAggregateVersion::forRecord(
                    previous: $exception,
                    aggregateId: $eventRecord->aggregateId->identityValue(),
                    aggregateType: $eventRecord->aggregateType,
                    aggregateVersion: $eventRecord->aggregateVersion->value
                );
            }

            throw DuplicateOutboxEvent::forRecord(
                eventId: $eventRecord->id->toString(),
                previous: $exception
            );
        }
    }
}
