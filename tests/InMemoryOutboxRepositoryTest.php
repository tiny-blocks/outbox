<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\TinyBlocks\Outbox\Mocks\InMemoryOutboxRepositoryMock;
use Test\TinyBlocks\Outbox\Mocks\InvalidPayloadSerializer;
use Test\TinyBlocks\Outbox\Mocks\InvalidSnapshotSerializer;
use Test\TinyBlocks\Outbox\Mocks\OrderPlacedSerializer;
use Test\TinyBlocks\Outbox\Models\EventRecordFactory;
use Test\TinyBlocks\Outbox\Models\Order;
use Test\TinyBlocks\Outbox\Models\OrderPlaced;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\BuildingBlocks\Event\SequenceNumber;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateSequence;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\InvalidPayloadJson;
use TinyBlocks\Outbox\Exceptions\InvalidSnapshotJson;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\Exceptions\SnapshotSerializerNotConfigured;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;
use TinyBlocks\Outbox\Serialization\SnapshotSerializerReflection;
use TinyBlocks\Outbox\Serialization\SnapshotSerializers;

final class InMemoryOutboxRepositoryTest extends TestCase
{
    public function testPushWhenSingleRecordThenItIsPersisted(): void
    {
        /** @Given an in-memory repository with configured serializers */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @When a single valid event record is pushed */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));

        /** @And the transaction is committed */
        $outbox->commit();

        /** @Then one record is persisted in the repository */
        self::assertCount(1, $outbox->persistedRecords());
    }

    public function testPushWhenMultipleRecordsThenAllArePersistedInOrder(): void
    {
        /** @Given an in-memory repository with configured serializers */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @When two event records are pushed in a single call */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                sequenceNumber: SequenceNumber::of(value: 1)
            ),
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                sequenceNumber: SequenceNumber::of(value: 2)
            )
        ]));

        /** @And the transaction is committed */
        $outbox->commit();

        /** @Then both records are persisted in the repository */
        self::assertCount(2, $outbox->persistedRecords());
    }

    public function testPushWhenNoTransactionIsActiveThenOutboxRequiresActiveTransaction(): void
    {
        /** @Given an in-memory repository without an active transaction */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @Then an exception requiring an active transaction is thrown */
        $this->expectException(OutboxRequiresActiveTransaction::class);

        /** @When pushing a record without starting a transaction */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
    }

    public function testPushWhenNoPayloadSerializerMatchesThenPayloadSerializerNotConfigured(): void
    {
        /** @Given an in-memory repository with no payload serializers configured */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFromEmpty(),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @Then an exception indicating no configured payload serializer is thrown */
        $this->expectException(PayloadSerializerNotConfigured::class);

        /** @When pushing a record whose event type has no matching serializer */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
    }

    public function testPushWhenNoSnapshotSerializerMatchesThenSnapshotSerializerNotConfigured(): void
    {
        /** @Given an in-memory repository with no snapshot serializers configured */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFromEmpty()
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @Then an exception indicating no configured snapshot serializer is thrown */
        $this->expectException(SnapshotSerializerNotConfigured::class);

        /** @When pushing a record whose aggregate type has no matching snapshot serializer */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
    }

    public function testPushWhenPayloadSerializerReturnsInvalidJsonThenInvalidPayloadJson(): void
    {
        /** @Given an in-memory repository with a serializer that produces invalid JSON */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new InvalidPayloadSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @Then an exception indicating invalid payload JSON is thrown */
        $this->expectException(InvalidPayloadJson::class);

        /** @When pushing a record whose serializer produces malformed JSON */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
    }

    public function testPushWhenSnapshotSerializerReturnsInvalidJsonThenInvalidSnapshotJson(): void
    {
        /** @Given an in-memory repository with a snapshot serializer that produces invalid JSON */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new InvalidSnapshotSerializer()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @Then an exception indicating invalid snapshot JSON is thrown */
        $this->expectException(InvalidSnapshotJson::class);

        /** @When pushing a record whose snapshot serializer produces malformed JSON */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
    }

    public function testPushWhenTwoRecordsShareTheSameIdThenDuplicateOutboxEvent(): void
    {
        /** @Given an in-memory repository with configured serializers */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @And a fixed event id shared by both records */
        $eventId = Uuid::uuid4();

        /** @And a first record with the fixed id is pushed */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                id: $eventId
            )
        ]));

        /** @Then a duplicate outbox event exception is thrown */
        $this->expectException(DuplicateOutboxEvent::class);

        /** @When a second record with the same id is pushed */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                id: $eventId,
                sequenceNumber: SequenceNumber::of(value: 2)
            )
        ]));
    }

    public function testPushWhenTwoRecordsShareTheSameAggregateSequenceThenDuplicateAggregateSequence(): void
    {
        /** @Given an in-memory repository with configured serializers */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @And a fixed aggregate identity shared by both records */
        $aggregateId = Uuid::uuid4()->toString();

        /** @And a first record with the fixed aggregate and sequence number 1 is pushed */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                sequenceNumber: SequenceNumber::of(value: 1)
            )
        ]));

        /** @Then a duplicate aggregate sequence exception is thrown */
        $this->expectException(DuplicateAggregateSequence::class);

        /** @When a second record with the same aggregate and sequence number is pushed */
        $outbox->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                sequenceNumber: SequenceNumber::of(value: 1)
            )
        ]));
    }

    public function testPushWhenEventRecordsIsEmptyThenNoRecordIsPersisted(): void
    {
        /** @Given an in-memory repository with configured serializers */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @When an empty EventRecords collection is pushed */
        $outbox->push(records: EventRecords::createFromEmpty());

        /** @And the transaction is committed */
        $outbox->commit();

        /** @Then no records are persisted in the repository */
        self::assertCount(0, $outbox->persistedRecords());
    }

    public function testPushWhenRealEventualAggregateRootThenEventRecordIsPersisted(): void
    {
        /** @Given an in-memory repository with a reflection payload serializer */
        $outbox = new InMemoryOutboxRepositoryMock(
            payloadSerializers: PayloadSerializers::createFrom(elements: [new PayloadSerializerReflection()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @And a transaction is started */
        $outbox->beginTransaction();

        /** @When the aggregate's recorded events are pushed */
        $outbox->push(records: Order::place(orderId: Uuid::uuid4()->toString())->recordedEvents());

        /** @And the transaction is committed */
        $outbox->commit();

        /** @Then one event record is persisted in the repository */
        self::assertCount(1, $outbox->persistedRecords());
    }
}
