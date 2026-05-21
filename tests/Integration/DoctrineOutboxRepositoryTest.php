<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;
use Test\TinyBlocks\Outbox\Models\EventRecordFactory;
use Test\TinyBlocks\Outbox\Models\Order;
use Test\TinyBlocks\Outbox\Models\OrderPlaced;
use Test\TinyBlocks\Outbox\Models\OrderPlacedTranslator;
use Test\TinyBlocks\Outbox\Models\RefundIssued;
use Test\TinyBlocks\Outbox\Models\RefundIssuedTranslator;
use Test\TinyBlocks\Outbox\Unit\DriverExceptionStub;
use Test\TinyBlocks\Outbox\Unit\InvalidPayloadSerializer;
use Test\TinyBlocks\Outbox\Unit\OrderPlacedSerializer;
use TinyBlocks\BuildingBlocks\Aggregate\AggregateVersion;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventTranslators;
use TinyBlocks\BuildingBlocks\Event\Revision;
use TinyBlocks\Outbox\DoctrineOutboxRepository;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateVersion;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\InvalidPayloadJson;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\Schema\Columns;
use TinyBlocks\Outbox\Schema\IdentityColumnType;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
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
        /** @Given a repository with a translator and two serializers supporting the same integration event */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [
                new OrderPlacedSerializer(),
                new FallbackOrderPlacedSerializer()
            ])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing an order placed event */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then the payload is from the first serializer, not the fallback */
        self::assertSame('{}', self::$connection->fetchOne('SELECT payload FROM outbox_events LIMIT 1'));
    }

    public function testPushWhenReflectionPayloadSerializerThenEventPropertiesAreEncoded(): void
    {
        /** @Given a repository with a translator and a reflection payload serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new PayloadSerializerReflection()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing a record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then the payload reflects the integration event's public properties */
        self::assertSame('[]', self::$connection->fetchOne('SELECT payload FROM outbox_events LIMIT 1'));
    }

    public function testPushWhenCallerRollsBackThenNoRecordPersisted(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing two records */
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

        /** @And the transaction is committed */
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing a record with the null-byte UUID */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                id: $recordId
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then the retrieved bytes are identical to the original UUID bytes */
        $row = self::$connection->fetchAssociative('SELECT id FROM outbox_events LIMIT 1');
        self::assertSame($recordId->getBytes(), $row['id']);
    }

    public function testPushWhenSerializerReturnsInvalidJsonThenInvalidPayloadJson(): void
    {
        /** @Given a repository with a translator and a serializer that produces invalid JSON */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new InvalidPayloadSerializer()])
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
        $this->expectExceptionMessage('Payload is not valid JSON <not json>.');

        /** @When pushing the record */
        $repository->push(records: $records);
    }

    public function testPushWhenMultipleSerializersAndSecondMatchesThenCorrectSerializerIsUsed(): void
    {
        /** @Given a repository with translators for both event types and matching serializers */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [
                new OrderPlacedTranslator(),
                new RefundIssuedTranslator()
            ]),
            serializers: PayloadSerializers::createFrom(elements: [
                new OrderPlacedSerializer(),
                new RefundIssuedSerializer()
            ])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing a refund event */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new RefundIssued(),
                aggregateType: 'Refund',
                eventTypeName: 'RefundIssued'
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then the payload is from the refund serializer */
        self::assertSame(
            '{"type": "refund"}',
            self::$connection->fetchOne('SELECT payload FROM outbox_events LIMIT 1')
        );
    }

    public function testPushWhenSerializerDoesNotSupportIntegrationEventThenPayloadSerializerNotConfigured(): void
    {
        /** @Given a repository with a refund translator but only an order serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new RefundIssuedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
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
            'No payload serializer configured for event class <Test\TinyBlocks\Outbox\Models\RefundCompleted>.'
        );

        /** @When pushing the unsupported event */
        $repository->push(records: $records);
    }

    public function testPushWhenDuplicateEventIdThenDuplicateOutboxEvent(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
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

    public function testPushWhenDuplicateAggregateVersionThenDuplicateAggregateVersion(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And a fixed aggregate identity and aggregate version */
        $aggregateId = Uuid::uuid4()->toString();

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a first record is pushed with that aggregate and aggregate version */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                aggregateVersion: AggregateVersion::of(value: 1)
            )
        ]));

        /** @Then an exception indicating a duplicate aggregate version is thrown */
        $this->expectException(DuplicateAggregateVersion::class);
        $this->expectExceptionMessage(
            sprintf('Duplicate aggregate version for <Order/%s> at aggregate version <1>.', $aggregateId)
        );

        /** @When pushing a second record with the same aggregate and aggregate version but a different id */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                aggregateVersion: AggregateVersion::of(value: 1)
            )
        ]));
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing a record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));

        /** @And the transaction is committed */
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing a record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));

        /** @And the transaction is committed */
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @And a non-UUID aggregate identity */
        $aggregateId = 'ord-1';

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing a record with a non-UUID aggregate id */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then the aggregate_id is stored as the original string */
        self::assertSame($aggregateId, self::$connection->fetchOne('SELECT aggregate_id FROM string_outbox LIMIT 1'));
    }

    public function testPushWhenSingleRecordThenAllFieldsPersistedCorrectly(): void
    {
        /** @Given a repository with a translator and an order placed serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing the record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                revision: Revision::of(value: 2),
                occurredAt: Instant::fromString(value: '2024-06-01 12:00:00.000000'),
                aggregateVersion: AggregateVersion::of(value: 3)
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then the row is retrievable from the database */
        $row = self::$connection->fetchAssociative('SELECT * FROM outbox_events LIMIT 1');

        /** @And the id is stored as 16-byte binary */
        self::assertSame(16, strlen($row['id']));

        /** @And the aggregate_id is stored as 16-byte binary */
        self::assertSame(16, strlen($row['aggregate_id']));

        /** @And the event_type reflects the integration event class */
        self::assertSame('OrderShipped', $row['event_type']);

        /** @And the revision is correct */
        self::assertSame(1, (int)$row['revision']);

        /** @And the aggregate_version is correct */
        self::assertSame(3, (int)$row['aggregate_version']);

        /** @And the payload matches the serializer output */
        self::assertSame('{}', $row['payload']);

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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And a record with a known id */
        $recordId = Uuid::uuid4();

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing the record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                id: $recordId
            )
        ]));

        /** @And the transaction is committed */
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
                    ->withCreatedAt(name: 'event_created_at')
                    ->withEventType(name: 'event_event_type')
                    ->withOccurredAt(name: 'event_occurred_at')
                    ->withAggregateId(name: 'event_aggregate_id', type: IdentityColumnType::BINARY)
                    ->withAggregateType(name: 'event_aggregate_type')
                    ->withAggregateVersion(name: 'event_aggregate_version')
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing a record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateVersion: AggregateVersion::of(value: 5)
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then the record is retrievable from the custom table */
        $row = self::$connection->fetchAssociative('SELECT * FROM custom_columns_outbox LIMIT 1');

        /** @And event_id is stored as 16-byte binary */
        self::assertSame(16, strlen($row['event_id']));

        /** @And event_aggregate_id is stored as 16-byte binary */
        self::assertSame(16, strlen($row['event_aggregate_id']));

        /** @And event_aggregate_type is correct */
        self::assertSame('Order', $row['event_aggregate_type']);

        /** @And event_event_type reflects the integration event class */
        self::assertSame('OrderShipped', $row['event_event_type']);

        /** @And event_revision is correct */
        self::assertSame(1, (int)$row['event_revision']);

        /** @And event_aggregate_version is correct */
        self::assertSame(5, (int)$row['event_aggregate_version']);

        /** @And event_payload matches the serializer output */
        self::assertSame('{}', $row['event_payload']);

        /** @And event_occurred_at is stored */
        self::assertNotNull($row['event_occurred_at']);

        /** @And event_created_at is auto-populated by the database */
        self::assertNotNull($row['event_created_at']);
    }

    public function testPushWhenEventRecordsIsEmptyThenNoInsertIsExecuted(): void
    {
        /** @Given a repository */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing an empty EventRecords collection */
        $repository->push(records: EventRecords::createFromEmpty());

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then no records are persisted */
        self::assertSame(0, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testPushWhenRealEventualAggregateRootThenEventRecordIsPersistedCorrectly(): void
    {
        /** @Given a repository with a translator and a reflection payload serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new PayloadSerializerReflection()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing the aggregate's recorded events */
        $repository->push(records: Order::place(orderId: Uuid::uuid4()->toString())->recordedEvents());

        /** @And the transaction is committed */
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        )->push(
            records: EventRecords::createFrom(elements: [
                EventRecordFactory::create(
                    event: new OrderPlaced(),
                    aggregateType: 'Order',
                    eventTypeName: 'OrderPlaced',
                    id: Uuid::fromString('550e8400-e29b-41d4-a716-446655440000'),
                    occurredAt: Instant::fromString(value: '2021-01-01T00:00:00+00:00'),
                    aggregateId: '6ba7b810-9dad-11d1-80b4-00c04fd430c8'
                )
            ])
        );

        /** @Then executeStatement receives all expected parameter bindings */
        self::assertSame(
            [
                'id'               => '550e8400-e29b-41d4-a716-446655440000',
                'aggregateId'      => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                'aggregateType'    => 'Order',
                'eventType'        => 'OrderShipped',
                'revision'         => 1,
                'aggregateVersion' => 1,
                'payload'          => '{}',
                'occurredAt'       => '2021-01-01T00:00:00+00:00'
            ],
            $capturedParameters
        );
    }

    public function testPushWhenUniqueConstraintOnAggregateVersionThenDuplicateAggregateVersionIsThrown(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = self::createConfiguredStub(Connection::class, ['isTransactionActive' => true]);

        /** @And the connection raises an aggregate version constraint violation */
        $connection->method('executeStatement')->willThrowException(
            new UniqueConstraintViolationException(
                new DriverExceptionStub(
                    'Duplicate entry for key unq_outbox_events_aggregate_type_aggregate_id_aggregate_version'
                ),
                null
            )
        );

        /** @Then a duplicate aggregate version exception is thrown */
        $this->expectException(DuplicateAggregateVersion::class);
        $this->expectExceptionMessage(
            'Duplicate aggregate version for <Order/6ba7b810-9dad-11d1-80b4-00c04fd430c8> at aggregate version <1>.'
        );

        /** @When pushing the record */
        new DoctrineOutboxRepository(
            connection: $connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
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
        $this->expectExceptionMessageMatches('/Event with id <.+> already exists in outbox\./');

        /** @When pushing the record */
        new DoctrineOutboxRepository(
            connection: $connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
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

    public function testPushWhenUniqueConstraintWithCustomNameThenDuplicateAggregateVersionIsThrown(): void
    {
        /** @Given a mocked connection with an active transaction */
        $connection = self::createConfiguredStub(Connection::class, ['isTransactionActive' => true]);

        /** @And a custom table layout with a distinct unique constraint name */
        $tableLayout = TableLayout::builder()
            ->withUniqueConstraint(name: 'unq_custom_outbox_aggregate_version')
            ->build();

        /** @And the connection raises a violation on the custom constraint name */
        $connection->method('executeStatement')->willThrowException(
            new UniqueConstraintViolationException(
                new DriverExceptionStub('Duplicate entry for key unq_custom_outbox_aggregate_version'),
                null
            )
        );

        /** @Then a duplicate aggregate version exception is thrown */
        $this->expectException(DuplicateAggregateVersion::class);

        /** @When pushing a record with the custom table layout */
        new DoctrineOutboxRepository(
            connection: $connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
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

    public function testPushWhenConstraintNameIsCustomThenDuplicateAggregateVersion(): void
    {
        /** @Given a custom table layout with a distinct unique constraint name */
        $tableLayout = TableLayout::builder()
            ->withTableName(tableName: 'custom_constraint_outbox')
            ->withUniqueConstraint(name: 'unq_custom_outbox_aggregate_version')
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
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()]),
            tableLayout: $tableLayout
        );

        /** @And a fixed aggregate identity */
        $aggregateId = Uuid::uuid4()->toString();

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @And a first record is pushed with that aggregate and aggregate version */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                aggregateVersion: AggregateVersion::of(value: 1)
            )
        ]));

        /** @Then an exception indicating a duplicate aggregate version is thrown */
        $this->expectException(DuplicateAggregateVersion::class);

        /** @When pushing a second record with the same aggregate and aggregate version */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateId: $aggregateId,
                aggregateVersion: AggregateVersion::of(value: 1)
            )
        ]));
    }

    public function testPushWhenNoTranslatorsRegisteredThenRecordIsSilentlySkipped(): void
    {
        /** @Given a repository with no translators and a reflection serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFromEmpty(),
            serializers: PayloadSerializers::createFrom(elements: [new PayloadSerializerReflection()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing an order placed record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then no records are persisted */
        self::assertSame(0, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testPushWhenOnlyOrderTranslatorRegisteredThenRefundEventIsSkipped(): void
    {
        /** @Given a repository with only an order translator and both serializers */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFrom(elements: [
                new OrderPlacedSerializer(),
                new RefundIssuedSerializer()
            ])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing one order placed and one refund issued record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced',
                aggregateVersion: AggregateVersion::of(value: 1)
            ),
            EventRecordFactory::create(
                event: new RefundIssued(),
                aggregateType: 'Refund',
                eventTypeName: 'RefundIssued',
                aggregateVersion: AggregateVersion::of(value: 1)
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then exactly one row is persisted */
        self::assertSame(1, (int)self::$connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));

        /** @And the persisted event_type is the order integration event */
        self::assertSame('OrderShipped', self::$connection->fetchOne('SELECT event_type FROM outbox_events LIMIT 1'));
    }

    public function testPushWhenTwoTranslatorsSupportSameEventThenFirstTranslatorWins(): void
    {
        /** @Given a repository with two translators both supporting order placed, and a matching serializer */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [
                new OrderPlacedTranslator(),
                new DuplicateOrderPlacedTranslator()
            ]),
            serializers: PayloadSerializers::createFrom(elements: [new OrderPlacedSerializer()])
        );

        /** @And the connection has an active transaction */
        self::$connection->beginTransaction();

        /** @When pushing an order placed record */
        $repository->push(records: EventRecords::createFrom(elements: [
            EventRecordFactory::create(
                event: new OrderPlaced(),
                aggregateType: 'Order',
                eventTypeName: 'OrderPlaced'
            )
        ]));

        /** @And the transaction is committed */
        self::$connection->commit();

        /** @Then the persisted event_type reflects the first translator's output */
        self::assertSame('OrderShipped', self::$connection->fetchOne('SELECT event_type FROM outbox_events LIMIT 1'));
    }

    public function testPushWhenTranslatorMatchesButNoSerializerThenPayloadSerializerNotConfigured(): void
    {
        /** @Given a repository with a translator but no serializer that matches the produced integration event */
        $repository = new DoctrineOutboxRepository(
            connection: self::$connection,
            translators: IntegrationEventTranslators::createFrom(elements: [new OrderPlacedTranslator()]),
            serializers: PayloadSerializers::createFromEmpty()
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
            'No payload serializer configured for event class <Test\TinyBlocks\Outbox\Models\OrderShipped>.'
        );

        /** @When pushing the record */
        $repository->push(records: $records);
    }
}
