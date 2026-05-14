<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateSequence;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\Exceptions\SnapshotSerializerNotConfigured;
use TinyBlocks\Outbox\Internal\OutboxInsert;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;
use TinyBlocks\Outbox\Serialization\SnapshotSerializerReflection;
use TinyBlocks\Outbox\Serialization\SnapshotSerializers;

final readonly class DoctrineOutboxRepository implements OutboxRepository
{
    private TableLayout $tableLayout;
    private SnapshotSerializers $snapshotSerializers;

    public function __construct(
        private Connection $connection,
        private PayloadSerializers $payloadSerializers,
        ?TableLayout $tableLayout = null,
        ?SnapshotSerializers $snapshotSerializers = null
    ) {
        $this->tableLayout = $tableLayout ?? TableLayout::default();
        $this->snapshotSerializers = $snapshotSerializers
            ?? SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()]);
    }

    public function push(EventRecords $records): void
    {
        if (!$this->connection->isTransactionActive()) {
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

            $insert = OutboxInsert::from(
                record: $record,
                payload: $payloadSerializer->serialize(record: $record),
                snapshot: $snapshotSerializer->serialize(record: $record),
                tableLayout: $this->tableLayout
            );

            try {
                $this->connection->executeStatement($insert->sql, $insert->parameters);
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->tableLayout->uniqueConstraint->isViolatedBy(exception: $exception)) {
                    throw new DuplicateAggregateSequence(
                        aggregateId: (string)$record->identity->identityValue(),
                        aggregateType: $record->aggregateType,
                        sequenceNumber: $record->sequenceNumber->value,
                        previous: $exception
                    );
                }

                throw DuplicateOutboxEvent::forRecord(eventId: $record->id, previous: $exception);
            }
        });
    }
}
