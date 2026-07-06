<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox;

use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\Outbox\Exceptions\DuplicateAggregateVersion;
use TinyBlocks\Outbox\Exceptions\DuplicateOutboxEvent;
use TinyBlocks\Outbox\Exceptions\InvalidPayloadJson;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Exceptions\PayloadSerializerNotConfigured;

/**
 * Producer-side contract: persists outbox records as part of the caller's open transaction.
 *
 * <p>Used by aggregate repositories during the business unit of work. The implementation must not
 * open or commit a transaction. Atomicity with the aggregate state change is the caller's
 * responsibility.</p>
 */
interface OutboxRepository
{
    /**
     * Persists the given records as part of the caller's open transaction.
     *
     * <p>The input carries domain events from the aggregate's recorded-events buffer. Every
     * record produces exactly one outbox row, so the unique constraint over aggregate type,
     * aggregate id, and aggregate version can detect lost updates on every state transition.</p>
     *
     * <p>Records with a matching <code>IntegrationEventTranslator</code> are translated into
     * <code>IntegrationEvent</code> envelopes via the Anti-Corruption Layer and only then
     * serialized and persisted. The persisted event type, revision, and payload come from the
     * integration event, and the <code>PayloadSerializer</code> operates on the integration
     * event record, never on the domain event directly.</p>
     *
     * <p>Records without a matching translator are persisted carrying the domain event itself.
     * The persisted event type and revision come from the domain event, and the payload is
     * produced by reflection over the domain event's public properties. No
     * <code>PayloadSerializer</code> is consulted on this path.</p>
     *
     * <p>The implementation must not open or commit a transaction. It is the caller's
     * responsibility to ensure this call happens inside the same unit of work as the
     * aggregate state change.</p>
     *
     * @param EventRecords $records The records to persist.
     * @throws InvalidPayloadJson When a serializer produces an invalid JSON payload.
     * @throws DuplicateOutboxEvent When a record with a duplicate id already exists in the outbox.
     * @throws DuplicateAggregateVersion When two records share the same aggregate type, id, and aggregate version.
     * @throws PayloadSerializerNotConfigured When a translator matched a domain event but no serializer supports the
     *                                        produced integration event.
     * @throws OutboxRequiresActiveTransaction When called outside an active transaction.
     */
    public function push(EventRecords $records): void;
}
