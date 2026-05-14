# Outbox

[![License](https://img.shields.io/badge/license-MIT-green)](https://github.com/tiny-blocks/outbox/blob/main/LICENSE)

* [Overview](#overview)
* [Installation](#installation)
* [How to use](#how-to-use)
    + [Expected table schema](#expected-table-schema)
    + [Wiring the repository](#wiring-the-repository)
    + [Producing events from an aggregate](#producing-events-from-an-aggregate)
    + [Customizing the table layout](#customizing-the-table-layout)
    + [Writing a custom payload serializer](#writing-a-custom-payload-serializer)
    + [Writing a custom snapshot serializer](#writing-a-custom-snapshot-serializer)
    + [Event schema versioning](#event-schema-versioning)
* [FAQ](#faq)
* [License](#license)
* [Contributing](#contributing)

## Overview

The **Transactional Outbox** pattern solves the dual-write problem: persisting an aggregate state change and publishing
a domain event must happen atomically. Doing both independently risks a crash leaving one side committed and the other
lost. The outbox pattern records both in the same database transaction, delegating event delivery to a separate relay
process.

This library is the write-side adapter. It persists outbox records via Doctrine DBAL and is opinionated on correctness.
Transactions are always required and JSON validity is always checked, while leaving every schema decision to you: table
name, column names, and identity column storage type are all configurable.

The library composes with [`tiny-blocks/building-blocks`](https://github.com/tiny-blocks/building-blocks), which
contributes `DomainEvent`, `DomainEventBehavior`, `EventRecord`, `EventRecords`, `EventType`, `Revision`,
`SequenceNumber`, `SnapshotData`, and the `EventualAggregateRoot` family. This library provides the persistence step
only.

## Installation

```
composer require tiny-blocks/outbox
```

## How to use

### Expected table schema

The library does not create or manage the outbox table. Add it in your own migration.

**Default schema (BINARY(16) identity columns, recommended for UUID-based aggregates):**

```sql
CREATE TABLE outbox_events (
    sequence        BIGINT       NOT NULL AUTO_INCREMENT UNIQUE,
    id              BINARY(16)   NOT NULL PRIMARY KEY,
    aggregate_type  VARCHAR(255) NOT NULL,
    aggregate_id    BINARY(16)   NOT NULL,
    event_type      VARCHAR(255) NOT NULL,
    revision        INT          NOT NULL,
    sequence_number BIGINT       NOT NULL,
    payload         JSON         NOT NULL,
    snapshot        JSON         NOT NULL,
    occurred_at     DATETIME(6)  NOT NULL,
    created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_aggregate (aggregate_id),
    UNIQUE KEY uniq_aggregate_sequence (aggregate_type, aggregate_id, sequence_number)
);
```

The library writes to `id`, `aggregate_id`, `aggregate_type`, `event_type`, `revision`, `sequence_number`, `payload`,
`snapshot`, and `occurred_at`. It never writes to `sequence` or `created_at`. The database fills those automatically.

For aggregates whose identities are not UUID v4 strings, use VARCHAR columns and configure `IdentityColumnType::STRING`
(see [Customizing the table layout](#customizing-the-table-layout)):

```sql
-- For non-UUID identities: VARCHAR(36) or wider.
id           VARCHAR(36) NOT NULL PRIMARY KEY,
aggregate_id VARCHAR(36) NOT NULL,
```

### Wiring the repository

`DoctrineOutboxRepository` requires a Doctrine DBAL `Connection` and a `PayloadSerializers` collection. The snapshot
serializer collection defaults to a `SnapshotSerializers` containing `SnapshotSerializerReflection`, and the table
layout defaults to table `outbox_events` with BINARY(16) identity columns.

```php
<?php

declare(strict_types=1);

use TinyBlocks\Outbox\DoctrineOutboxRepository;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;

$repository = new DoctrineOutboxRepository(
    connection: $connection,
    payloadSerializers: PayloadSerializers::createFrom(elements: [
        new PayloadSerializerReflection()
    ])
);
```

`PayloadSerializerReflection` serializes any event whose public properties are scalars or `JsonSerializable`. Register
specific serializers before it for events that need custom shaping (see
[Writing a custom payload serializer](#writing-a-custom-payload-serializer)).

| Parameter             | Type                  | Required | Description                                                                            |
|-----------------------|-----------------------|----------|----------------------------------------------------------------------------------------|
| `connection`          | `Connection`          | Yes      | Doctrine DBAL connection used for all INSERT statements                                |
| `payloadSerializers`  | `PayloadSerializers`  | Yes      | Ordered collection of payload serializers; first match wins                            |
| `tableLayout`         | `TableLayout`         | No       | Table and column configuration; defaults to `outbox_events` with BINARY(16) ids        |
| `snapshotSerializers` | `SnapshotSerializers` | No       | Ordered collection of snapshot serializers; defaults to `SnapshotSerializerReflection` |

### Producing events from an aggregate

Aggregates that implement `EventualAggregateRoot` and use `EventualAggregateRootBehavior` record domain events in an
internal buffer as state changes occur. The application layer drains that buffer into the outbox inside the same
database transaction that persists the aggregate state.

```php
<?php

declare(strict_types=1);

use TinyBlocks\BuildingBlocks\Event\DomainEvent;
use TinyBlocks\BuildingBlocks\Event\DomainEventBehavior;
use TinyBlocks\Outbox\OutboxRepository;

# A domain event. DomainEventBehavior provides revision() defaulting to revision 1.
final readonly class OrderPlaced implements DomainEvent
{
    use DomainEventBehavior;

    public function __construct(public string $orderId)
    {
    }
}

# Both the aggregate state change and the outbox write happen inside the same transaction.
$connection->beginTransaction();

try {
    $order = Order::place(orderId: 'order-123');
    $orderRepository->save(order: $order);
    $outboxRepository->push(records: $order->recordedEvents());
    $connection->commit();
} catch (Throwable $exception) {
    $connection->rollBack();
    throw $exception;
}
```

The aggregate instance is use-once: its recorded-events buffer is never cleared by the library. Discard the instance
after `push()`. Re-saving the same instance pushes the same records again and throws `DuplicateOutboxEvent`.

### Customizing the table layout

`TableLayout::builder()` controls the table name. `Columns::builder()` renames individual columns and
switches identity column storage between BINARY(16) and STRING.

```php
<?php

declare(strict_types=1);

use TinyBlocks\Outbox\DoctrineOutboxRepository;
use TinyBlocks\Outbox\Schema\IdentityColumnType;
use TinyBlocks\Outbox\Schema\Columns;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;

$tableLayout = TableLayout::builder()
    ->withTableName(tableName: 'domain_events')
    ->withColumns(columns: Columns::builder()
        ->withId(name: 'id', type: IdentityColumnType::STRING)
        ->withEventType(name: 'kind')
        ->withAggregateId(name: 'aggregate_id', type: IdentityColumnType::STRING)
        ->withAggregateType(name: 'entity_class')
        ->withSequenceNumber(name: 'position')
        ->build())
    ->build();

$repository = new DoctrineOutboxRepository(
    connection: $connection,
    payloadSerializers: PayloadSerializers::createFrom(elements: [new PayloadSerializerReflection()]),
    tableLayout: $tableLayout
);
```

All `Columns::builder()` methods are optional. Omit any method to keep its default.
`withId` and `withAggregateId` require both `name:` and `type:`; all other methods require only `name:`.

| Method                          | Default column name | Default type | Description                                                     |
|---------------------------------|---------------------|--------------|-----------------------------------------------------------------|
| `withId(name:, type:)`          | `id`                | `BINARY`     | Renames the event id column and/or changes its storage type     |
| `withAggregateId(name:, type:)` | `aggregate_id`      | `BINARY`     | Renames the aggregate id column and/or changes its storage type |
| `withAggregateType(name:)`      | `aggregate_type`    | —            | Renames the aggregate type column                               |
| `withEventType(name:)`          | `event_type`        | —            | Renames the event type column                                   |
| `withRevision(name:)`           | `revision`          | —            | Renames the schema revision column                              |
| `withSequenceNumber(name:)`     | `sequence_number`   | —            | Renames the aggregate sequence number column                    |
| `withPayload(name:)`            | `payload`           | —            | Renames the event payload column                                |
| `withSnapshot(name:)`           | `snapshot`          | —            | Renames the aggregate snapshot column                           |
| `withOccurredAt(name:)`         | `occurred_at`       | —            | Renames the event timestamp column                              |
| `withCreatedAt(name:)`          | `created_at`        | —            | Renames the record creation timestamp column                    |

`TableLayout::builder()` controls the table name, columns, and unique constraint name.

| Method                        | Default                   | Description                                                        |
|-------------------------------|---------------------------|--------------------------------------------------------------------|
| `withTableName(tableName:)`   | `outbox_events`           | Sets the outbox table name                                         |
| `withColumns(columns:)`       | Default column names      | Provides a custom `Columns` configuration                          |
| `withUniqueConstraint(name:)` | `uniq_aggregate_sequence` | Sets the unique constraint name used to detect duplicate sequences |

The DDL example uses `uniq_aggregate_sequence` as the unique constraint name. The library expects this name by
default; if you rename it in your DDL, configure it via
`TableLayout::builder()->withUniqueConstraint(name: 'your_name')->build()`.

Constraint violation detection works with MySQL, MariaDB, PostgreSQL, and SQL Server. These DBMSs include the
constraint name in their violation messages. SQLite is not supported because it omits the constraint name.
All unique violations with SQLite fall under `DuplicateOutboxEvent`.

### Writing a custom payload serializer

`PayloadSerializerReflection` covers events whose public properties are scalars or `JsonSerializable`. Implement
`PayloadSerializer` explicitly for events that contain value objects or domain types that need custom JSON shaping.

Both `supports()` and `serialize()` receive the full `EventRecord`, giving access to `$record->event`,
`$record->aggregateType`, `$record->snapshotData`, and all other fields when routing or shaping the payload.

Use `match (true)` in `serialize()` to handle multiple event types from the same aggregate in a single serializer:

```php
<?php

declare(strict_types=1);

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Outbox\Serialization\PayloadSerializer;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;
use TinyBlocks\Outbox\Serialization\SerializedPayload;

final readonly class OrderEventSerializer implements PayloadSerializer
{
    public function supports(EventRecord $record): bool
    {
        return $record->event instanceof OrderPlaced || $record->event instanceof OrderShipped;
    }

    public function serialize(EventRecord $record): SerializedPayload
    {
        return match (true) {
            $record->event instanceof OrderPlaced => SerializedPayload::from(
                payload: json_encode(['orderId' => $record->event->orderId], JSON_THROW_ON_ERROR)
            ),
            $record->event instanceof OrderShipped => SerializedPayload::from(
                payload: json_encode(
                    ['orderId' => $record->event->orderId, 'shippedAt' => $record->event->shippedAt],
                    JSON_THROW_ON_ERROR
                )
            )
        };
    }
}

# Register custom serializers before PayloadSerializerReflection.
# PayloadSerializerReflection always returns true from supports(), so it must come last.
$repository = new DoctrineOutboxRepository(
    connection: $connection,
    payloadSerializers: PayloadSerializers::createFrom(elements: [
        new OrderEventSerializer(),
        new PayloadSerializerReflection()
    ])
);
```

`SerializedPayload::from()` validates the JSON string at construction time and throws `InvalidPayloadJson` if the JSON
is malformed, before the INSERT is attempted. When building the JSON from an array, prefer
`SerializedPayload::fromArray($array)` over `SerializedPayload::from(json_encode($array, JSON_THROW_ON_ERROR))`. The
library handles encoding internally.

### Writing a custom snapshot serializer

The aggregate snapshot records the state at the time of each event. By default, `SnapshotSerializerReflection`
serializes `$record->snapshotData->toArray()` using `json_encode`. Provide a custom `SnapshotSerializer` when the
snapshot payload contains value objects or domain types that are not directly JSON-encodable.

Both `supports()` and `serialize()` receive the full `EventRecord`, giving access to `$record->snapshotData`,
`$record->aggregateType`, `$record->event`, and all other fields when routing or shaping the snapshot.

```php
<?php

declare(strict_types=1);

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Outbox\DoctrineOutboxRepository;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;
use TinyBlocks\Outbox\Serialization\SerializedSnapshot;
use TinyBlocks\Outbox\Serialization\SnapshotSerializer;
use TinyBlocks\Outbox\Serialization\SnapshotSerializerReflection;
use TinyBlocks\Outbox\Serialization\SnapshotSerializers;

final readonly class OrderSnapshotSerializer implements SnapshotSerializer
{
    public function supports(EventRecord $record): bool
    {
        return $record->aggregateType === 'Order';
    }

    public function serialize(EventRecord $record): SerializedSnapshot
    {
        $state = $record->snapshotData->toArray();

        return SerializedSnapshot::from(
            snapshot: json_encode(
                ['orderId' => $state['orderId']->value, 'status' => $state['status']],
                JSON_THROW_ON_ERROR
            )
        );
    }
}

# Register custom snapshot serializers before SnapshotSerializerReflection.
# SnapshotSerializerReflection always returns true from supports(), so it must come last.
$repository = new DoctrineOutboxRepository(
    connection: $connection,
    payloadSerializers: PayloadSerializers::createFrom(elements: [new PayloadSerializerReflection()]),
    snapshotSerializers: SnapshotSerializers::createFrom(elements: [
        new OrderSnapshotSerializer(),
        new SnapshotSerializerReflection()
    ])
);
```

`SerializedSnapshot::from()` validates the JSON string at construction time and throws `InvalidSnapshotJson` if the
JSON is malformed, before the INSERT is attempted. When building the JSON from an array, prefer
`SerializedSnapshot::fromArray($array)` over `SerializedSnapshot::from(json_encode($array, JSON_THROW_ON_ERROR))`. The
library handles encoding internally.

### Event schema versioning

Each domain event declares its schema revision via `DomainEvent::revision()`. `DomainEventBehavior` provides the
default implementation, returning revision 1. Override `revision()` when the event's payload structure changes:

```php
<?php

declare(strict_types=1);

use TinyBlocks\BuildingBlocks\Event\DomainEvent;
use TinyBlocks\BuildingBlocks\Event\DomainEventBehavior;
use TinyBlocks\BuildingBlocks\Event\Revision;

# Revision 2: a currency field was added. Override revision() to declare the schema bump.
final readonly class OrderPlaced implements DomainEvent
{
    use DomainEventBehavior;

    public function __construct(
        public string $orderId,
        public string $currency
    ) {
    }

    public function revision(): Revision
    {
        return Revision::of(value: 2);
    }
}
```

The `revision` column stored in the outbox lets downstream consumers detect schema changes.
`tiny-blocks/building-blocks`
provides `Upcaster`, `Upcasters`, `IntermediateEvent`, and `SingleUpcasterBehavior` to migrate events from older
revisions to the current schema on the read side.

## FAQ

### 01. Why don't I need a custom serializer for each event?

`PayloadSerializerReflection` uses PHP's `get_object_vars()` to serialize any event whose public properties are scalars
or `JsonSerializable`. It always returns `true` from `supports()`, making it a universal catch-all when registered last
in `PayloadSerializers::createFrom()`. Only events with value objects or domain types that are not directly
JSON-encodable require an explicit serializer.

### 02. What is the difference between revision and sequence_number?

`revision` is a schema version declared on the **event class** via `DomainEvent::revision()`. It starts at 1 and is
bumped when the event's payload structure changes. `sequence_number` is the **aggregate's internal position counter**,
incremented once per recorded event. They are independent: `revision` tracks event schema evolution;
`sequence_number` tracks the position of an event within a single aggregate's history.

### 03. Why does push require an active transaction?

The Transactional Outbox pattern's core guarantee is that the outbox record is committed atomically with the aggregate
state change. If `push()` were allowed outside a transaction, a crash between the aggregate save and the outbox write
would leave one side committed and the other lost. `OutboxRequiresActiveTransaction` makes this contract explicit.
Calling `push()` without an active `beginTransaction()` is always a programming error.

### 04. When does each duplication exception fire?

`DuplicateOutboxEvent` fires when an `EventRecord` with the same `id` already exists in the outbox (PRIMARY KEY
violation). `DuplicateAggregateSequence` fires when two records share the same `aggregate_type`, `aggregate_id`, and
`sequence_number` (`uniq_aggregate_sequence` unique constraint violation). The latter typically indicates concurrent
producers writing to the same aggregate position without proper locking. Both extend `RuntimeException` and can be
caught independently for precise idempotency handling.

### 05. Why is BINARY(16) the default for identity columns?

UUID v4 identifiers are 128 bits, which fit exactly in 16 bytes. Storing them as `BINARY(16)` instead of `VARCHAR(36)`
saves 20 bytes per row on each identity column and indexes more efficiently in B-tree structures. Aggregate identities
that are not UUID v4 strings, for example ULID, snowflake, integer, or opaque strings, must configure
`IdentityColumnType::STRING` via `Columns::builder()` and use a compatible column type in the schema.

### 06. Does this library read events from the outbox?

No. `OutboxRepository` is a write-only interface. Reading, relaying, and processing outbox records is the
responsibility of a separate relay worker, which is outside the scope of this library.

### 07. What is the authoritative ordering source for outbox records?

The `sequence` column (`BIGINT AUTO_INCREMENT`) is the authoritative global ordering. The database fills it at commit
time, so it reflects the actual commit order across concurrent transactions, the only safe ordering baseline under
concurrent writes. `occurred_at` records when the domain event happened and is subject to clock skew across processes
or nodes; do not use it as a primary ordering source. `sequence_number` gives ordering within a single aggregate's
history only.

### 08. Why do PayloadSerializer and SnapshotSerializer both receive EventRecord instead of DomainEvent or SnapshotData?

Routing and shaping decisions often depend on context beyond the event itself. For example, a single serializer may
handle events from a specific aggregate type (`$record->aggregateType === 'Order'`), or the snapshot shaping may vary
based on which event triggered the state change (`$record->event`). Receiving the full `EventRecord` in both
`supports()` and `serialize()` gives serializers access to all available context without requiring any additional
indirection.

### 09. How does the library handle transient database errors?

The library catches `UniqueConstraintViolationException` to differentiate
`DuplicateAggregateSequence` from `DuplicateOutboxEvent`. All other DBAL
exceptions, including transient errors like deadlocks (`DeadlockException`),
lock wait timeouts (`LockWaitTimeoutException`), and connection failures
(`ConnectionLost`), propagate unchanged to the caller.

The consumer is responsible for any retry policy. A common pattern is to wrap
the unit of work (aggregate save + outbox push) in a retry loop that catches
transient exceptions and re-executes the entire transaction.

## License

Outbox is licensed under [MIT](LICENSE).

## Contributing

Please follow the [contributing guidelines](https://github.com/tiny-blocks/tiny-blocks/blob/main/CONTRIBUTING.md) to
contribute to the project.
