<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventTranslators;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateVersion;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\Internal\OutboxInsert;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;

final readonly class DoctrineOutboxRepository implements OutboxRepository
{
    private TableLayout $tableLayout;

    public function __construct(
        private Connection $connection,
        private IntegrationEventTranslators $translators,
        private PayloadSerializers $serializers,
        ?TableLayout $tableLayout = null
    ) {
        $this->tableLayout = $tableLayout ?? TableLayout::default();
    }

    public function push(EventRecords $records): void
    {
        if (!$this->connection->isTransactionActive()) {
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

            $payloadSerializer = $this->serializers->findFor(record: $integrationEventRecord);

            if (is_null($payloadSerializer)) {
                throw PayloadSerializerNotConfigured::forEventClass(
                    eventClass: $integrationEventRecord->event::class
                );
            }

            $insert = OutboxInsert::from(
                record: $integrationEventRecord,
                payload: $payloadSerializer->serialize(record: $integrationEventRecord),
                tableLayout: $this->tableLayout
            );

            try {
                $this->connection->executeStatement(sql: $insert->sql, params: $insert->parameters);
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->tableLayout->uniqueConstraint->isViolatedBy(exception: $exception)) {
                    throw DuplicateAggregateVersion::forRecord(
                        previous: $exception,
                        aggregateId: $integrationEventRecord->aggregateId->identityValue(),
                        aggregateType: $integrationEventRecord->aggregateType,
                        aggregateVersion: $integrationEventRecord->aggregateVersion->value
                    );
                }

                throw DuplicateOutboxEvent::forRecord(
                    eventId: $integrationEventRecord->id->toString(),
                    previous: $exception
                );
            }
        });
    }
}
