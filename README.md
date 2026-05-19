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
`AggregateVersion`, and the `EventualAggregateRoot` family. This library provides the persistence step only.

## Installation

```
composer require tiny-blocks/outbox
```

## How to use

### Expected table schema

The library does not create or manage the outbox table. Add it in your own migration.

**Default schema (BINARY(16) identity columns, recommended for UUID-based aggregates):**

```sql
CREATE TABLE outbox_events
(
    id                BINARY(16)   NOT NULL COMMENT 'The event identifier in Version 4 UUID format (e.g. 123e4567-e89b-12d3-a456-426614174000).',
    payload           JSON         NOT NULL COMMENT 'The event payload serialized as a JSON object (e.g. {"transaction_id":"..."}).',
    revision          INT          NOT NULL COMMENT 'The positive integer indicating the payload schema revision of the event (e.g. 1).',
    event_type        VARCHAR(255) NOT NULL COMMENT 'The event class name in CamelCase (e.g. TransactionConfirmed).',
    occurred_at       TIMESTAMP(6) NOT NULL COMMENT 'The UTC date and time when the event occurred in ISO 8601 format (e.g. 2026-02-13T08:49:44.931408+00:00).',
    aggregate_id      BINARY(16)   NOT NULL COMMENT 'The aggregate root identifier in Version 4 UUID format (e.g. 123e4567-e89b-12d3-a456-426614174000).',
    aggregate_type    VARCHAR(255) NOT NULL COMMENT 'The aggregate root class name that produced the event in CamelCase (e.g. Transaction).',
    aggregate_version BIGINT       NOT NULL COMMENT 'The version of the aggregate at the moment the event was emitted, used to detect duplicate or out-of-order events per aggregate (e.g. 1).',
    created_at        TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'The UTC date and time when the record was inserted in ISO 8601 format (e.g. 2026-02-13T08:49:44.931408+00:00).',
    PRIMARY KEY (id),
    CONSTRAINT unq_outbox_events_aggregate_type_aggregate_id_aggregate_version UNIQUE (aggregate_type, aggregate_id, aggregate_version)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci COMMENT ='Table used to persist append-only outbox events for atomic event publication.';
```

The library writes to `id`, `aggregate_id`, `aggregate_type`, `event_type`, `revision`, `aggregate_version`, `payload`,
and `occurred_at`. It never writes to `created_at`. The database fills it automatically.

For aggregates whose identities are not UUID v4 strings, use VARCHAR columns and configure `IdentityColumnType::STRING`
(see [Customizing the table layout](#customizing-the-table-layout)):

```sql
CREATE TABLE outbox_events
(
    -- For non-UUID identities: VARCHAR(36) or wider.
    id           VARCHAR(36) NOT NULL,
    aggregate_id VARCHAR(36) NOT NULL
    -- All other columns are the same as the default schema.
)
```

### Wiring the repository

`DoctrineOutboxRepository` requires a Doctrine DBAL `Connection` and a `PayloadSerializers` collection. The table layout
defaults to table `outbox_events` with BINARY(16) identity columns.

```php
<?php

declare(strict_types=1);

use TinyBlocks\Outbox\DoctrineOutboxRepository;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;

$repository = new DoctrineOutboxRepository(
    connection: $connection,
    serializers: serializers::createFrom(elements: [
        new PayloadSerializerReflection()
    ])
);
```

`PayloadSerializerReflection` serializes any event whose public properties are scalars or `JsonSerializable`. Register
specific serializers before it for events that need custom shaping (see
[Writing a custom payload serializer](#writing-a-custom-payload-serializer)).

| Parameter     | Type                 | Required | Description                                                                      |
|---------------|----------------------|:--------:|----------------------------------------------------------------------------------|
| `connection`  | `Connection`         |   Yes    | Doctrine DBAL connection used for all INSERT statements.                         |
| `serializers` | `PayloadSerializers` |   Yes    | Ordered collection of payload serializers, first match wins.                     |
| `tableLayout` | `TableLayout`        |    No    | Table and column configuration, defaults to `outbox_events` with BINARY(16) ids. |

### Producing events from an aggregate

Aggregates that implement `EventualAggregateRoot` and use `EventualAggregateRootBehavior` record domain events in an
internal buffer as state changes occur. The application layer drains that buffer into the outbox inside the same
database transaction that persists the aggregate state.

```php
<?php

declare(strict_types=1);

use TinyBlocks\BuildingBlocks\Event\DomainEvent;
use TinyBlocks\BuildingBlocks\Event\DomainEventBehavior;

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

`TableLayout::builder()` controls the table name. `Columns::builder()` renames individual columns and switches identity
column storage between BINARY(16) and STRING.

```php
<?php

declare(strict_types=1);

use TinyBlocks\Outbox\DoctrineOutboxRepository;
use TinyBlocks\Outbox\Schema\Columns;
use TinyBlocks\Outbox\Schema\IdentityColumnType;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\PayloadSerializerReflection;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;

$tableLayout = TableLayout::builder()
    ->withColumns(columns: Columns::builder()
        ->withId(name: 'id', type: IdentityColumnType::STRING)
        ->withEventType(name: 'kind')
        ->withAggregateId(name: 'aggregate_id', type: IdentityColumnType::STRING)
        ->withAggregateType(name: 'entity_class')
        ->withAggregateVersion(name: 'position')
        ->build())
    ->withTableName(tableName: 'my_outbox')
    ->build();

$repository = new DoctrineOutboxRepository(
    connection: $connection,
    serializers: serializers::createFrom(elements: [new PayloadSerializerReflection()]),
    tableLayout: $tableLayout
);
```

All `Columns::builder()` methods are optional. Omit any method to keep its default. `withId` and `withAggregateId`
require both `name:` and `type:`, all other methods require only `name:`.

| Method                          | Default column name | Default type | Description                                                      |
|---------------------------------|---------------------|:------------:|------------------------------------------------------------------|
| `withId(name:, type:)`          | `id`                |   `BINARY`   | Renames the event id column and/or changes its storage type.     |
| `withPayload(name:)`            | `payload`           |              | Renames the event payload column.                                |
| `withRevision(name:)`           | `revision`          |              | Renames the schema revision column.                              |
| `withEventType(name:)`          | `event_type`        |              | Renames the event type column.                                   |
| `withOccurredAt(name:)`         | `occurred_at`       |              | Renames the event timestamp column.                              |
| `withAggregateId(name:, type:)` | `aggregate_id`      |   `BINARY`   | Renames the aggregate id column and/or changes its storage type. |
| `withAggregateType(name:)`      | `aggregate_type`    |              | Renames the aggregate type column.                               |
| `withAggregateVersion(name:)`   | `aggregate_version` |              | Renames the aggregate version column.                            |
| `withCreatedAt(name:)`          | `created_at`        |              | Renames the record creation timestamp column.                    |

`TableLayout::builder()` controls the table name, columns, and unique constraint name.

| Method                        | Default                                                           | Description.                                                       |
|-------------------------------|-------------------------------------------------------------------|--------------------------------------------------------------------|
| `withColumns(columns:)`       | Default column names.                                             | Provides a custom `Columns` configuration.                         |
| `withTableName(tableName:)`   | `outbox_events`                                                   | Sets the outbox table name.                                        |
| `withUniqueConstraint(name:)` | `unq_outbox_events_aggregate_type_aggregate_id_aggregate_version` | Sets the unique constraint name used to detect duplicate versions. |

The DDL example uses `unq_outbox_events_aggregate_type_aggregate_id_aggregate_version` as the unique constraint name.
The library expects this name by default, if you rename it in your DDL, configure it via
`TableLayout::builder()->withUniqueConstraint(name: 'your_name')->build()`.

Constraint violation detection works with MySQL, MariaDB, PostgreSQL, and SQL Server. These DBMSs include the
constraint name in their violation messages. SQLite is not supported because it omits the constraint name. All unique
violations with SQLite fall under `DuplicateOutboxEvent`.

### Writing a custom payload serializer

`PayloadSerializerReflection` covers events whose public properties are scalars or `JsonSerializable`. Implement
`PayloadSerializer` explicitly for events that contain value objects or domain types that need custom JSON shaping.

Both `supports()` and `serialize()` receive the full `EventRecord`, giving access to `$record->event`,
`$record->aggregateType`, `$record->aggregateId`, `$record->aggregateVersion`, and all other fields when routing or
shaping the payload.

Use `match (true)` in `serialize()` to handle multiple event types from the same aggregate in a single serializer:

```php
<?php

declare(strict_types=1);

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Outbox\DoctrineOutboxRepository;
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
    serializers: serializers::createFrom(elements: [
        new OrderEventSerializer(),
        new PayloadSerializerReflection()
    ])
);
```

`SerializedPayload::from()` validates the JSON string at construction time and throws `InvalidPayloadJson` if the JSON
is malformed, before the INSERT is attempted. When building the JSON from an array, prefer
`SerializedPayload::fromArray($array)` over `SerializedPayload::from(json_encode($array, JSON_THROW_ON_ERROR))`. The
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

    public function __construct(public string $orderId, public string $currency) {
    }

    public function revision(): Revision
    {
        return Revision::of(value: 2);
    }
}
```

The `revision` column stored in the outbox lets downstream consumers detect schema changes.
`tiny-blocks/building-blocks` provides `Upcaster`, `Upcasters`, `IntermediateEvent`, and `SingleUpcasterBehavior` to
migrate events from older revisions to the current schema on the read side.

## FAQ

### 01. Why don't I need a custom serializer for each event?

`PayloadSerializerReflection` uses PHP's `get_object_vars()` to serialize any event whose public properties are scalars
or `JsonSerializable`. It always returns `true` from `supports()`, making it a universal catch-all when registered last
in `serializers::createFrom()`. Only events with value objects or domain types that are not directly
JSON-encodable require an explicit serializer.

### 02. What is the difference between revision and aggregate_version?

`revision` is a schema version declared on the **event class** via `DomainEvent::revision()`. It starts at 1 and is
bumped when the event's payload structure changes. `aggregate_version` is the **aggregate's internal version counter**,
incremented once per recorded event and used for optimistic offline locking. They are independent: `revision` tracks
event schema evolution, `aggregate_version` tracks the position of an event within a single aggregate's history.

### 03. Why does push require an active transaction?

The Transactional Outbox pattern's core guarantee is that the outbox record is committed atomically with the aggregate
state change. If `push()` were allowed outside a transaction, a crash between the aggregate save and the outbox write
would leave one side committed and the other lost. `OutboxRequiresActiveTransaction` makes this contract explicit.
Calling `push()` without an active `beginTransaction()` is always a programming error.

### 04. When does each duplication exception fire?

`DuplicateOutboxEvent` fires when an `EventRecord` with the same `id` already exists in the outbox (PRIMARY KEY
violation). `DuplicateAggregateVersion` fires when two records share the same `aggregate_type`, `aggregate_id`, and
`aggregate_version` (`unq_outbox_events_aggregate_type_aggregate_id_aggregate_version` constraint violation). The
latter typically indicates concurrent producers writing to the same aggregate version without proper locking. Both
extend `RuntimeException` and can be caught independently for precise idempotency handling.

### 05. Why is BINARY(16) the default for identity columns?

UUID v4 identifiers are 128 bits, which fit exactly in 16 bytes. Storing them as `BINARY(16)` instead of `VARCHAR(36)`
saves 20 bytes per row on each identity column and indexes more efficiently in B-tree structures. Aggregate identities
that are not UUID v4 strings, for example ULID, snowflake, integer, or opaque strings, must configure
`IdentityColumnType::STRING` via `Columns::builder()` and use a compatible column type in the schema.

### 06. Does this library read events from the outbox?

No. `OutboxRepository` is a write-only interface. Reading, relaying, and processing outbox records is the
responsibility of a separate relay worker, which is outside the scope of this library.

### 07. How are outbox records ordered?

Per-aggregate ordering is guaranteed by `aggregate_version`: a monotonic counter, unique per aggregate, that advances by
one for each recorded event. Cross-aggregate ordering is the relay's responsibility. Common strategies include using a
time-ordered identifier for `id` (e.g. UUID v7), or ordering by `created_at` on the relay side. `occurred_at` records
when the domain event happened in the producing process and is subject to clock skew, do not use it as a primary
ordering source.

### 08. Why does PayloadSerializer receive EventRecord instead of DomainEvent?

Routing and shaping decisions often depend on context beyond the event itself. For example, a single serializer may
handle events from a specific aggregate type (`$record->aggregateType === 'Order'`), or the payload shaping may vary
based on the aggregate version (`$record->aggregateVersion`). Receiving the full `EventRecord` in both `supports()` and
`serialize()` gives serializers access to all available context without requiring any additional indirection.

### 09. How does the library handle transient database errors?

The library catches `UniqueConstraintViolationException` to differentiate `DuplicateAggregateVersion` from
`DuplicateOutboxEvent`. All other DBAL exceptions, including transient errors like deadlocks (`DeadlockException`),
lock wait timeouts (`LockWaitTimeoutException`), and connection failures (`ConnectionLost`), propagate unchanged to the
caller.

The consumer is responsible for any retry policy. A common pattern is to wrap the unit of work (aggregate save +
outbox push) in a retry loop that catches transient exceptions and re-executes the entire transaction.

## License

Outbox is licensed under [MIT](LICENSE).

## Contributing

Please follow the [contributing guidelines](https://github.com/tiny-blocks/tiny-blocks/blob/main/CONTRIBUTING.md) to
contribute to the project.
