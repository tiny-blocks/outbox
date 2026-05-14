<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox;

use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateSequence;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\InvalidPayloadJson;
use TinyBlocks\Outbox\Exceptions\InvalidSnapshotJson;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;
use TinyBlocks\Outbox\Exceptions\SnapshotSerializerNotConfigured;

/**
 * Producer-side contract: persists outbox records as part of the caller's open transaction.
 *
 * <p>Used by aggregate repositories during the business unit of work. The implementation must not
 * open or commit a transaction. Atomicity with the aggregate state change is the caller's responsibility.</p>
 */
interface OutboxRepository
{
    /**
     * Persists the given records as part of the caller's open transaction.
     *
     * <p>The implementation must not open or commit a transaction. It is the caller's responsibility
     * to ensure this call happens inside the same unit of work as the aggregate state change.</p>
     *
     * @param EventRecords $records The records to persist.
     * @throws OutboxRequiresActiveTransaction When called outside an active transaction.
     * @throws PayloadSerializerNotConfigured When no serializer supports the event class.
     * @throws SnapshotSerializerNotConfigured When no serializer supports the aggregate type.
     * @throws InvalidPayloadJson When a serializer produces an invalid JSON payload.
     * @throws InvalidSnapshotJson When a serializer produces an invalid JSON snapshot.
     * @throws DuplicateOutboxEvent When a record with a duplicate id already exists in the outbox.
     * @throws DuplicateAggregateSequence When two records share the same aggregate type, id, and sequence number.
     */
    public function push(EventRecords $records): void;
}
