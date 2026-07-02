<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox;

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

    public function __construct(
        private Connection $connection,
        PayloadSerializers $serializers,
        IntegrationEventTranslators $translators,
        ?TableLayout $tableLayout = null
    ) {
        $this->tableLayout = ($tableLayout ?? TableLayout::default());
        $this->writer = new OutboxWriter(
            connection: $connection,
            serializers: $serializers,
            tableLayout: $this->tableLayout,
            translators: $translators
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
