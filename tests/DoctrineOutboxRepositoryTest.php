<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;
use Test\TinyBlocks\Outbox\Mocks\CustomOrderSnapshotSerializer;
use Test\TinyBlocks\Outbox\Mocks\DriverExceptionStub;
use Test\TinyBlocks\Outbox\Mocks\FallbackOrderPlacedSerializer;
use Test\TinyBlocks\Outbox\Mocks\InvalidPayloadSerializer;
use Test\TinyBlocks\Outbox\Mocks\InvalidSnapshotSerializer;
use Test\TinyBlocks\Outbox\Mocks\OrderPlacedSerializer;
use Test\TinyBlocks\Outbox\Mocks\RefundIssuedSerializer;
use Test\TinyBlocks\Outbox\Models\EventRecordFactory;
use Test\TinyBlocks\Outbox\Models\Order;
use Test\TinyBlocks\Outbox\Models\OrderPlaced;
use Test\TinyBlocks\Outbox\Models\RefundIssued;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\BuildingBlocks\Event\Revision;
use TinyBlocks\BuildingBlocks\Event\SequenceNumber;
use TinyBlocks\Outbox\DoctrineOutboxRepository;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateSequence;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\InvalidPayloadJson;
use TinyBlocks\Outbox\Exceptions\InvalidSnapshotJson;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\Exceptions\SnapshotSerializerNotConfigured;
use TinyBlocks\Outbox\Schema\Columns;
use TinyBlocks\Outbox\Schema\IdentityColumnType;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;
use TinyBlocks\Outbox\Serialization\SnapshotSerializerReflection;
use TinyBlocks\Outbox\Serialization\SnapshotSerializers;
use TinyBlocks\Time\Instant;

final class DoctrineOutboxRepositoryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        self::$connection->executeStatement('DROP TABLE IF EXISTS outbox_events');
        OutboxTableFactory::createWithBinaryIdentities(
            connection: self::$connection,
            tableLayout: TableLayout::default()
        );
    }

    public function testPushWhenNoTransactionThenOutboxRequiresActiveTransaction(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And a record to push */
        $records = EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]);

        /** @Then an exception requiring an active transaction is thrown */
        $this->expectException(OutboxRequiresActiveTransaction::class);
        $this->expectExceptionMessage('push() must be called within an active transaction.');

        /** @When pushing without an active transaction */
        $repository->push(records: $records);
    }

    public function testPushWhenMultipleSerializersAndFirstMatchesThenFirstIsUsed(): void
    {
        /** @Given a repository with two serializers supporting the same event */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [
                new OrderPlacedSerializer(),
                new FallbackOrderPlacedSerializer()
            ])
        );

        /** @When pushing an order placed event inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
        self::$connection->commit();

        /** @Then the payload is from the first serializer, not the fallback */
        self::assertSame('{}', self::$connection->fetchOne('SELECT payload FROM outbox_events LIMIT 1'));
    }

    public function testPushWhenReflectionPayloadSerializerThenEventPropertiesAreEncoded(): void
    {
        /** @Given a repository using ReflectionPayloadSerializer as the only serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new PayloadSerializerReflection()])
        );

        /** @When pushing a record inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
        self::$connection->commit();

        /** @Then the payload reflects the event's public properties */
        self::assertSame('[]', self::$connection->fetchOne('SELECT payload FROM outbox_events LIMIT 1'));
    }

    public function testPushWhenCallerRollsBackThenNoRecordPersisted(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And the caller opens a transaction */
        self::$connection->beginTransaction();

        /** @And a record is pushed inside the caller transaction */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));

        /** @When the caller rolls back the transaction */
        self::$connection->rollBack();

        /** @Then no records are persisted */
        self::assertSame(0, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testPushWhenTwoRecordsThenBothPersisted(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @When pushing two records inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            ),
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
        self::$connection->commit();

        /** @Then exactly two records are stored */
        self::assertSame(2, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testPushWhenUuidWithNullBytesThenBytesPreservedInStorage(): void
    {
        /** @Given a UUID whose bytes include a leading null byte */
        $recordId = Uuid::fromBytes(bytes: "\x00\x11\x22\x33\x44\x55\x67\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff");

        /** @And a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @When pushing a record with the null-byte UUID inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                id: $recordId
            )
        ]));
        self::$connection->commit();

        /** @Then the retrieved bytes are identical to the original UUID bytes */
        $row = self::$connection->fetchAssociative('SELECT id FROM outbox_events LIMIT 1');
        self::assertSame($recordId->getBytes(), $row['id']);
    }

    public function testPushWhenSerializerReturnsInvalidJsonThenInvalidPayloadJson(): void
    {
        /** @Given a repository with a serializer that produces invalid JSON */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new InvalidPayloadSerializer()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a record to push */
        $records = EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]);

        /** @Then an exception indicating invalid JSON payload is thrown */
        $this->expectException(InvalidPayloadJson::class);
        $this->expectExceptionMessage('Payload is not valid JSON: not json');

        /** @When pushing the record */
        $repository->push(records: $records);
    }

    public function testPushWhenMultipleSerializersAndSecondMatchesThenCorrectSerializerIsUsed(): void
    {
        /** @Given a repository with an order serializer followed by a refund serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [
                new OrderPlacedSerializer(),
                new RefundIssuedSerializer()
            ])
        );

        /** @When pushing a refund event inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new RefundIssued(),
                aggregateType: 'Refund',
                eventTypeName: 'RefundIssued'
            )
        ]));
        self::$connection->commit();

        /** @Then the payload is from the refund serializer */
        self::assertSame(
            '{"type": "refund"}',
            self::$connection->fetchOne('SELECT payload FROM outbox_events LIMIT 1')
        );
    }

    public function testPushWhenNoSerializerSupportsThenPayloadSerializerNotConfigured(): void
    {
        /** @Given a repository with no serializers */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFromEmpty()
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a record to push */
        $records = EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]);

        /** @Then an exception indicating no serializer is configured is thrown */
        $this->expectException(PayloadSerializerNotConfigured::class);
        $this->expectExceptionMessage(
            'No payload serializer configured for event class <Test\TinyBlocks\Outbox\Models\OrderPlaced>.'
        );

        /** @When pushing the record */
        $repository->push(records: $records);
    }

    public function testPushWhenSerializerDoesNotSupportEventThenPayloadSerializerNotConfigured(): void
    {
        /** @Given a repository with only an order serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a refund event that the order serializer does not support */
        $records = EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new RefundIssued(),
                aggregateType: 'Refund',
                eventTypeName: 'RefundIssued'
            )
        ]);

        /** @Then an exception indicating no serializer is configured is thrown */
        $this->expectException(PayloadSerializerNotConfigured::class);
        $this->expectExceptionMessage(
            'No payload serializer configured for event class <Test\TinyBlocks\Outbox\Models\RefundIssued>.'
        );

        /** @When pushing the unsupported event */
        $repository->push(records: $records);
    }

    public function testPushWhenNoSnapshotSerializerSupportsThenSnapshotSerializerNotConfigured(): void
    {
        /** @Given a repository with empty snapshot serializers */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFromEmpty()
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a record to push */
        $records = EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]);

        /** @Then an exception indicating no snapshot serializer is configured is thrown */
        $this->expectException(SnapshotSerializerNotConfigured::class);
        $this->expectExceptionMessage('No snapshot serializer configured for aggregate type "Order".');

        /** @When pushing the record */
        $repository->push(records: $records);
    }

    public function testPushWhenDuplicateEventIdThenDuplicateOutboxEvent(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And a record with a fixed id */
        $records = EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]);

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And the record is pushed once */
        $repository->push(records: $records);

        /** @Then an exception indicating a duplicate event is thrown */
        $this->expectException(DuplicateOutboxEvent::class);

        /** @When pushing the same record again */
        $repository->push(records: $records);
    }

    public function testPushWhenDuplicateAggregateSequenceThenDuplicateAggregateSequence(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And a fixed aggregate identity and sequence number */
        $aggregateId = Uuid::uuid4()->toString();

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a first record is pushed with that aggregate and sequence number */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                sequenceNumber: SequenceNumber::of(value: 1)
            )
        ]));

        /** @Then an exception indicating a duplicate aggregate sequence is thrown */
        $this->expectException(DuplicateAggregateSequence::class);
        $this->expectExceptionMessage(
            sprintf('Duplicate aggregate sequence for <Order/%s> at sequence number <1>.', $aggregateId)
        );

        /** @When pushing a second record with the same aggregate and sequence number but a different id */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                sequenceNumber: SequenceNumber::of(value: 1)
            )
        ]));
    }

    public function testPushWhenNoSnapshotSerializerThenReflectionSnapshotSerializerIsUsed(): void
    {
        /** @Given a repository without an explicit snapshot serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @When pushing a record with snapshot data inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                snapshot: ['status' => 'placed']
            )
        ]));
        self::$connection->commit();

        /** @Then the snapshot is stored using the default reflection serializer */
        self::assertSame(
            '{"status": "placed"}',
            self::$connection->fetchOne('SELECT snapshot FROM outbox_events LIMIT 1')
        );
    }

    public function testPushWhenCustomSnapshotSerializerThenCustomSnapshotIsUsed(): void
    {
        /** @Given a repository with a custom snapshot serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new CustomOrderSnapshotSerializer()])
        );

        /** @When pushing a record inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
        self::$connection->commit();

        /** @Then the snapshot uses the custom serializer output */
        self::assertSame('{"custom": true}', self::$connection->fetchOne('SELECT snapshot FROM outbox_events LIMIT 1'));
    }

    public function testPushWhenExplicitReflectionSnapshotSerializerThenBehaviorMatchesDefault(): void
    {
        /** @Given a repository with ReflectionSnapshotSerializer explicitly provided */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new SnapshotSerializerReflection()])
        );

        /** @When pushing a record with snapshot data inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                snapshot: ['status' => 'placed']
            )
        ]));
        self::$connection->commit();

        /** @Then the snapshot is identical to the default behavior */
        self::assertSame(
            '{"status": "placed"}',
            self::$connection->fetchOne('SELECT snapshot FROM outbox_events LIMIT 1')
        );
    }

    public function testPushWhenCustomTableNameThenRecordStoredInCustomTable(): void
    {
        /** @Given a custom table layout with a different table name */
        $tableLayout = TableLayout::builder()
            ->withTableName(tableName: 'custom_outbox')
            ->build();

        /** @And any pre-existing custom table is dropped */
        self::$connection->executeStatement('DROP TABLE IF EXISTS custom_outbox');

        /** @And the custom table is registered for cleanup */
        self::registerTableForCleanup(tableName: 'custom_outbox');

        /** @And the custom table is created */
        OutboxTableFactory::createWithBinaryIdentities(
            connection: self::$connection,
            tableLayout: $tableLayout
        );

        /** @And a repository using the custom layout */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @When pushing a record inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
        self::$connection->commit();

        /** @Then the record is in the custom table */
        self::assertSame(1, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM custom_outbox'));

        /** @And the default table remains empty */
        self::assertSame(0, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testPushWhenStringIdentityTypeStoredThenIdIsUuidString(): void
    {
        /** @Given a layout with STRING identity columns */
        $tableLayout = TableLayout::builder()
            ->withTableName(tableName: 'string_outbox')
            ->withColumns(
                columns: Columns::builder()
                    ->withId(name: 'id', type: IdentityColumnType::STRING)
                    ->withAggregateId(name: 'aggregate_id', type: IdentityColumnType::STRING)
                    ->build()
            )
            ->build();

        /** @And any pre-existing string outbox table is dropped */
        self::$connection->executeStatement('DROP TABLE IF EXISTS string_outbox');

        /** @And the string outbox table is registered for cleanup */
        self::registerTableForCleanup(tableName: 'string_outbox');

        /** @And the string outbox table is created */
        OutboxTableFactory::createWithStringIdentities(
            connection: self::$connection,
            tableLayout: $tableLayout
        );

        /** @And a repository using the string layout */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @When pushing a record inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));
        self::$connection->commit();

        /** @Then the id is stored as a 36-character UUID string */
        $row = self::$connection->fetchAssociative('SELECT id FROM string_outbox LIMIT 1');
        self::assertSame(36, strlen($row['id']));
    }

    public function testPushWhenNonUuidAggregateIdWithStringTypeThenStoredAsOriginalString(): void
    {
        /** @Given a layout with STRING identity columns */
        $tableLayout = TableLayout::builder()
            ->withTableName(tableName: 'string_outbox')
            ->withColumns(
                columns: Columns::builder()
                    ->withId(name: 'id', type: IdentityColumnType::STRING)
                    ->withAggregateId(name: 'aggregate_id', type: IdentityColumnType::STRING)
                    ->build()
            )
            ->build();

        /** @And any pre-existing string outbox table is dropped */
        self::$connection->executeStatement('DROP TABLE IF EXISTS string_outbox');

        /** @And the string outbox table is registered for cleanup */
        self::registerTableForCleanup(tableName: 'string_outbox');

        /** @And the string outbox table is created */
        OutboxTableFactory::createWithStringIdentities(
            connection: self::$connection,
            tableLayout: $tableLayout
        );

        /** @And a repository using the string layout */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @And a non-UUID aggregate identity */
        $aggregateId = 'ord-1';

        /** @When pushing a record with a non-UUID aggregate id inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId
            )
        ]));
        self::$connection->commit();

        /** @Then the aggregate_id is stored as the original string */
        self::assertSame($aggregateId, self::$connection->fetchOne('SELECT aggregate_id FROM string_outbox LIMIT 1'));
    }

    public function testPushWhenSingleRecordThenAllFieldsPersistedCorrectly(): void
    {
        /** @Given a repository with an order placed serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @When pushing the record inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                revision: Revision::of(value: 2),
                snapshot: ['status' => 'placed'],
                occurredOn: Instant::fromString(value: '2024-06-01 12:00:00.000000'),
                sequenceNumber: SequenceNumber::of(value: 3)
            )
        ]));
        self::$connection->commit();

        /** @Then the row is retrievable from the database */
        $row = self::$connection->fetchAssociative('SELECT * FROM outbox_events LIMIT 1');

        /** @And the id is stored as 16-byte binary */
        self::assertSame(16, strlen($row['id']));

        /** @And the aggregate_id is stored as 16-byte binary */
        self::assertSame(16, strlen($row['aggregate_id']));

        /** @And the event_type is correct */
        self::assertSame('OrderPlaced', $row['event_type']);

        /** @And the revision is correct */
        self::assertSame(2, (int)$row['revision']);

        /** @And the sequence_number is correct */
        self::assertSame(3, (int)$row['sequence_number']);

        /** @And the payload matches the serializer output */
        self::assertSame('{}', $row['payload']);

        /** @And the snapshot contains the aggregate state */
        self::assertSame('{"status": "placed"}', $row['snapshot']);

        /** @And the payload and snapshot store distinct JSON objects */
        self::assertNotSame($row['payload'], $row['snapshot']);

        /** @And the aggregate_type is correct */
        self::assertSame('Order', $row['aggregate_type']);

        /** @And the occurred_at is stored */
        self::assertStringStartsWith('2024-06-01', $row['occurred_at']);
    }

    public function testPushWhenKnownIdThenPersistedIdMatchesOriginal(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And a record with a known id */
        $recordId = Uuid::uuid4();

        /** @When pushing the record inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                id: $recordId
            )
        ]));
        self::$connection->commit();

        /** @Then the persisted id bytes match the original record id */
        $row = self::$connection->fetchAssociative(
            'SELECT id FROM outbox_events WHERE id = UUID_TO_BIN(?)',
            [$recordId->toString()]
        );
        self::assertSame($recordId->getBytes(), $row['id']);
    }

    public function testPushWhenAllColumnNamesAreCustomThenRecordStoredInCustomColumns(): void
    {
        /** @Given a layout with all column names customized */
        $tableLayout = TableLayout::builder()
            ->withTableName(tableName: 'custom_columns_outbox')
            ->withColumns(
                columns: Columns::builder()
                    ->withId(name: 'event_id', type: IdentityColumnType::BINARY)
                    ->withPayload(name: 'event_payload')
                    ->withRevision(name: 'event_revision')
                    ->withSnapshot(name: 'event_snapshot')
                    ->withCreatedAt(name: 'event_created_at')
                    ->withEventType(name: 'event_event_type')
                    ->withOccurredAt(name: 'event_occurred_at')
                    ->withAggregateId(name: 'event_aggregate_id', type: IdentityColumnType::BINARY)
                    ->withAggregateType(name: 'event_aggregate_type')
                    ->withSequenceNumber(name: 'event_sequence_number')
                    ->build()
            )
            ->build();

        /** @And any pre-existing table is dropped */
        self::$connection->executeStatement('DROP TABLE IF EXISTS custom_columns_outbox');

        /** @And the custom columns table is registered for cleanup */
        self::registerTableForCleanup(tableName: 'custom_columns_outbox');

        /** @And the table is created with custom column names */
        OutboxTableFactory::createWithBinaryIdentities(
            connection: self::$connection,
            tableLayout: $tableLayout
        );

        /** @And a repository using this layout */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @When pushing a record inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                sequenceNumber: SequenceNumber::of(value: 5)
            )
        ]));
        self::$connection->commit();

        /** @Then the record is retrievable from the custom table */
        $row = self::$connection->fetchAssociative('SELECT * FROM custom_columns_outbox LIMIT 1');

        /** @And event_id is stored as 16-byte binary */
        self::assertSame(16, strlen($row['event_id']));

        /** @And event_aggregate_id is stored as 16-byte binary */
        self::assertSame(16, strlen($row['event_aggregate_id']));

        /** @And event_aggregate_type is correct */
        self::assertSame('Order', $row['event_aggregate_type']);

        /** @And event_event_type is correct */
        self::assertSame('OrderPlaced', $row['event_event_type']);

        /** @And event_revision is correct */
        self::assertSame(1, (int)$row['event_revision']);

        /** @And event_sequence_number is correct */
        self::assertSame(5, (int)$row['event_sequence_number']);

        /** @And event_payload matches the serializer output */
        self::assertSame('{}', $row['event_payload']);

        /** @And event_snapshot is stored */
        self::assertNotNull($row['event_snapshot']);

        /** @And event_occurred_at is stored */
        self::assertNotNull($row['event_occurred_at']);

        /** @And event_created_at is auto-populated by the database */
        self::assertNotNull($row['event_created_at']);
    }

    public function testPushWhenSnapshotSerializerReturnsInvalidJsonThenInvalidSnapshotJson(): void
    {
        /** @Given a repository with a snapshot serializer that produces invalid JSON */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new InvalidSnapshotSerializer()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a record to push */
        $records = EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]);

        /** @Then an exception indicating invalid JSON snapshot is thrown */
        $this->expectException(InvalidSnapshotJson::class);
        $this->expectExceptionMessage('Snapshot is not valid JSON: not json');

        /** @When pushing the record */
        $repository->push(records: $records);
    }

    public function testPushWhenEventRecordsIsEmptyThenNoInsertIsExecuted(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @When pushing an empty EventRecords collection inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: EventRecords::createFromEmpty());
        self::$connection->commit();

        /** @Then no records are persisted */
        self::assertSame(0, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testPushWhenRealEventualAggregateRootThenEventRecordIsPersistedCorrectly(): void
    {
        /** @Given a repository with a reflection payload serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new PayloadSerializerReflection()])
        );

        /** @When pushing the aggregate's recorded events inside a transaction */
        self::$connection->beginTransaction();
        $repository->push(records: Order::place(orderId: Uuid::uuid4()->toString())->recordedEvents());
        self::$connection->commit();

        /** @Then one outbox record is persisted */
        self::assertSame(1, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));

        /** @And the aggregate type is Order */
        self::assertSame('Order', self::$connection->fetchOne('SELECT aggregate_type FROM outbox_events LIMIT 1'));
    }

    public function testPushWhenNullTableLayoutThenSqlUsesDefaultTableName(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = $this->createMock(Connection::class);

        /** @And the connection reports an active transaction */
        $connection->method('isTransactionActive')->willReturn(true);

        /** @Then the SQL statement targets the default table name */
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(self::stringContains('outbox_events'))
            ->willReturn(1);

        /** @When pushing a record using the default table layout */
        new DoctrineOutboxRepository(
            connection: $connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced'
                )
            ])
        );
    }

    public function testPushWhenCustomTableLayoutThenSqlUsesCustomTableName(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = $this->createMock(Connection::class);

        /** @And the connection reports an active transaction */
        $connection->method('isTransactionActive')->willReturn(true);

        /** @And a custom table layout with a distinct table name */
        $tableLayout = TableLayout::builder()->withTableName(tableName: 'custom_outbox')->build();

        /** @Then the SQL statement targets the custom table name */
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(self::stringContains('custom_outbox'))
            ->willReturn(1);

        /** @When pushing a record using the custom table layout */
        new DoctrineOutboxRepository(
            connection: $connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced'
                )
            ])
        );
    }

    public function testPushWhenNullSnapshotSerializersThenExecuteStatementIsCalled(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = $this->createMock(Connection::class);

        /** @And the connection reports an active transaction */
        $connection->method('isTransactionActive')->willReturn(true);

        /** @Then the statement is executed exactly once */
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);

        /** @When pushing a record using the default snapshot serializers */
        new DoctrineOutboxRepository(
            connection: $connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced'
                )
            ])
        );
    }

    public function testPushWhenCustomSnapshotSerializersThenExecuteStatementUsesCustomSnapshot(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = $this->createMock(Connection::class);

        /** @And the connection reports an active transaction */
        $connection->method('isTransactionActive')->willReturn(true);

        /** @Then the statement is executed with the custom snapshot value */
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::anything(),
                self::callback(static fn(array $params): bool => $params['snapshot'] === '{"custom":true}')
            )
            ->willReturn(1);

        /** @When pushing a record using custom snapshot serializers */
        new DoctrineOutboxRepository(
            connection: $connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            snapshotSerializers: SnapshotSerializers::createFrom(elements: [new CustomOrderSnapshotSerializer()])
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced'
                )
            ])
        );
    }

    public function testPushWhenRecordHasAllFieldsThenExecuteStatementReceivesAllBindings(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = $this->createMock(Connection::class);

        /** @And the connection reports an active transaction */
        $connection->method('isTransactionActive')->willReturn(true);

        /** @And a table layout with string identity columns */
        $tableLayout = TableLayout::builder()
            ->withColumns(
                columns: Columns::builder()
                    ->withId(name: 'id', type: IdentityColumnType::STRING)
                    ->withAggregateId(name: 'aggregate_id', type: IdentityColumnType::STRING)
                    ->build()
            )
            ->build();

        /** @And a variable to capture the parameters passed to executeStatement */
        $capturedParameters = null;

        /** @And the connection captures all parameters on executeStatement */
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(
                function (string $sql, array $params) use (&$capturedParameters): int {
                    $capturedParameters = $params;
                    return 1;
                }
            );

        /** @When pushing a record with all deterministic fields */
        new DoctrineOutboxRepository(
            connection: $connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced',
                    id: Uuid::fromString('550e8400-e29b-41d4-a716-446655440000'),
                    occurredOn: Instant::fromString(value: '2021-01-01T00:00:00+00:00'),
                    aggregateId: '6ba7b810-9dad-11d1-80b4-00c04fd430c8'
                )
            ])
        );

        /** @Then executeStatement receives all nine expected parameter bindings */
        self::assertSame(
            [
                'id'             => '550e8400-e29b-41d4-a716-446655440000',
                'aggregateId'    => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                'aggregateType'  => 'Order',
                'eventType'      => 'OrderPlaced',
                'revision'       => 1,
                'sequenceNumber' => 1,
                'payload'        => '{}',
                'snapshot'       => '[]',
                'occurredAt'     => '2021-01-01T00:00:00+00:00'
            ],
            $capturedParameters
        );
    }

    public function testPushWhenUniqueConstraintOnSequenceNumberThenDuplicateAggregateSequenceIsThrown(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = self::createConfiguredStub(Connection::class, ['isTransactionActive' => true]);

        /** @And the connection raises a sequence number constraint violation */
        $connection->method('executeStatement')->willThrowException(
            new UniqueConstraintViolationException(
                new DriverExceptionStub('Duplicate entry for key uniq_aggregate_sequence'),
                null
            )
        );

        /** @Then a duplicate aggregate sequence exception is thrown */
        $this->expectException(DuplicateAggregateSequence::class);
        $this->expectExceptionMessage(
            'Duplicate aggregate sequence for <Order/6ba7b810-9dad-11d1-80b4-00c04fd430c8> at sequence number <1>.'
        );

        /** @When pushing the record */
        new DoctrineOutboxRepository(
            connection: $connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced',
                    aggregateId: '6ba7b810-9dad-11d1-80b4-00c04fd430c8'
                )
            ])
        );
    }

    public function testPushWhenUniqueConstraintOnEventIdThenDuplicateOutboxEventIsThrown(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = self::createConfiguredStub(Connection::class, ['isTransactionActive' => true]);

        /** @And the connection raises a duplicate event id violation */
        $connection->method('executeStatement')->willThrowException(
            new UniqueConstraintViolationException(
                new DriverExceptionStub('Duplicate entry for key PRIMARY'),
                null
            )
        );

        /** @Then a duplicate outbox event exception is thrown */
        $this->expectException(DuplicateOutboxEvent::class);
        $this->expectExceptionMessageMatches('/Event with id ".+" already exists in outbox\./');

        /** @When pushing the record */
        new DoctrineOutboxRepository(
            connection: $connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced'
                )
            ])
        );
    }

    public function testPushWhenUniqueConstraintWithCustomNameThenDuplicateAggregateSequenceIsThrown(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = self::createConfiguredStub(Connection::class, ['isTransactionActive' => true]);

        /** @And a custom table layout with a distinct unique constraint name */
        $tableLayout = TableLayout::builder()
            ->withUniqueConstraint(name: 'uniq_custom_outbox_sequence')
            ->build();

        /** @And the connection raises a violation on the custom constraint name */
        $connection->method('executeStatement')->willThrowException(
            new UniqueConstraintViolationException(
                new DriverExceptionStub('Duplicate entry for key uniq_custom_outbox_sequence'),
                null
            )
        );

        /** @Then a duplicate aggregate sequence exception is thrown */
        $this->expectException(DuplicateAggregateSequence::class);

        /** @When pushing a record with the custom table layout */
        new DoctrineOutboxRepository(
            connection: $connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced'
                )
            ])
        );
    }

    public function testPushWhenConstraintNameIsCustomThenDuplicateAggregateSequence(): void
    {
        /** @Given a custom table layout with a distinct unique constraint name */
        $tableLayout = TableLayout::builder()
            ->withTableName(tableName: 'custom_constraint_outbox')
            ->withUniqueConstraint(name: 'uniq_custom_outbox_sequence')
            ->build();

        /** @And any pre-existing table is dropped */
        self::$connection->executeStatement('DROP TABLE IF EXISTS custom_constraint_outbox');

        /** @And the table is registered for cleanup */
        self::registerTableForCleanup(tableName: 'custom_constraint_outbox');

        /** @And the table is created with the custom constraint name */
        OutboxTableFactory::createWithBinaryIdentities(
            connection: self::$connection,
            tableLayout: $tableLayout
        );

        /** @And a repository using the custom table layout */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            payloadSerializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @And a fixed aggregate identity */
        $aggregateId = Uuid::uuid4()->toString();

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a first record is pushed with that aggregate and sequence number */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                sequenceNumber: SequenceNumber::of(value: 1)
            )
        ]));

        /** @Then an exception indicating a duplicate aggregate sequence is thrown */
        $this->expectException(DuplicateAggregateSequence::class);

        /** @When pushing a second record with the same aggregate and sequence number */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                sequenceNumber: SequenceNumber::of(value: 1)
            )
        ]));
    }
}
