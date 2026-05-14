<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\SerializedPayload;
use TinyBlocks\Outbox\Serialization\SerializedSnapshot;

final readonly class OutboxInsert
{
    private function __construct(public string $sql, public array $parameters)
    {
    }

    public static function from(
        EventRecord $record,
        SerializedPayload $payload,
        SerializedSnapshot $snapshot,
        TableLayout $tableLayout
    ): OutboxInsert {
        $template = <<<SQL
        INSERT INTO %s (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        VALUES (:id, :aggregateId, :aggregateType, :eventType, :revision,
                :sequenceNumber, :payload, :snapshot, :occurredAt)
        SQL;

        $columns = $tableLayout->columns;
        $idValue = $columns->id->convert(identityValue: $record->id);
        $aggregateIdValue = $columns->aggregateId->convert(identityValue: $record->identity->identityValue());

        return new OutboxInsert(
            sql: sprintf(
                $template,
                $tableLayout->tableName,
                $columns->id->name,
                $columns->aggregateId->name,
                $columns->aggregateType,
                $columns->eventType,
                $columns->revision,
                $columns->sequenceNumber,
                $columns->payload,
                $columns->snapshot,
                $columns->occurredAt
            ),
            parameters: [
                'id'             => $idValue,
                'aggregateId'    => $aggregateIdValue,
                'aggregateType'  => $record->aggregateType,
                'eventType'      => $record->type->value,
                'revision'       => $record->revision->value,
                'sequenceNumber' => $record->sequenceNumber->value,
                'payload'        => $payload->toJson(),
                'snapshot'       => $snapshot->toJson(),
                'occurredAt'     => $record->occurredOn->toIso8601()
            ]
        );
    }
}
