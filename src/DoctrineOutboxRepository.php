<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox;

use Closure;
use Doctrine\DBAL\Connection;
use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\EventRecords;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventTranslators;
use TinyBlocks\Outbox\Exceptions\OutboxRequiresActiveTransaction;
use TinyBlocks\Outbox\Internal\OutboxWriter;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\PayloadSerializers;

final readonly class DoctrineOutboxRepository implements OutboxRepository
{
    private TableLayout $tableLayout;
    private OutboxWriter $writer;

    /**
     * @param Connection $connection The connection whose open transaction the rows join.
     * @param PayloadSerializers $serializers The ordered payload serializers, first match wins.
     * @param IntegrationEventTranslators $translators The ordered translators from domain to integration events.
     * @param TableLayout|null $tableLayout The table and column configuration, the default layout when null.
     * @param (Closure(): string)|null $correlationId Reads the correlation id of the unit of work in flight at
     *                                                each write, returning an empty string when there is none. It is
     *                                                written only when the layout enables the correlation id column.
     */
    public function __construct(
        private Connection $connection,
        PayloadSerializers $serializers,
        IntegrationEventTranslators $translators,
        ?TableLayout $tableLayout = null,
        ?Closure $correlationId = null
    ) {
        $this->tableLayout = ($tableLayout ?? TableLayout::default());
        $this->writer = new OutboxWriter(
            connection: $connection,
            serializers: $serializers,
            tableLayout: $this->tableLayout,
            translators: $translators,
            correlationId: $correlationId
        );
    }

    public function push(EventRecords $records): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw OutboxRequiresActiveTransaction::asMissing();
        }

        $records->each(actions: function (EventRecord $eventRecord): void {
            $this->writer->write(eventRecord: $eventRecord);
        });
    }
}
