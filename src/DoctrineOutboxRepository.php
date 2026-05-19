<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
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

        $records->each(actions: function (EventRecord $record): void {
            $payloadSerializer = $this->serializers->findFor(record: $record);

            if (is_null($payloadSerializer)) {
                throw PayloadSerializerNotConfigured::forEventClass(eventClass: $record->event::class);
            }

            $insert = OutboxInsert::from(
                record: $record,
                payload: $payloadSerializer->serialize(record: $record),
                tableLayout: $this->tableLayout
            );

            try {
                $this->connection->executeStatement(sql: $insert->sql, params: $insert->parameters);
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->tableLayout->uniqueConstraint->isViolatedBy(exception: $exception)) {
                    throw DuplicateAggregateVersion::forRecord(
                        previous: $exception,
                        aggregateId: $record->aggregateId->identityValue(),
                        aggregateType: $record->aggregateType,
                        aggregateVersion: $record->aggregateVersion->value
                    );
                }

                throw DuplicateOutboxEvent::forRecord(eventId: $record->id, previous: $exception);
            }
        });
    }
}
