<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\SerializedPayload;

final readonly class OutboxInsert
{
    private function __construct(public string $sql, public array $parameters)
    {
    }

    public static function from(
        EventRecord $record,
        SerializedPayload $payload,
        TableLayout $tableLayout
    ): OutboxInsert {
        $template = <<<SQL
        INSERT INTO %s (%s, %s, %s, %s, %s, %s, %s, %s)
        VALUES (:id, :aggregateId, :aggregateType, :eventType, :revision,
                :aggregateVersion, :payload, :occurredAt)
        SQL;

        $columns = $tableLayout->columns;
        $idValue = $columns->id->convert(identityValue: $record->id);
        $aggregateIdValue = $columns->aggregateId->convert(identityValue: $record->aggregateId->identityValue());

        return new OutboxInsert(
            sql: sprintf(
                $template,
                $tableLayout->tableName,
                $columns->id->name,
                $columns->aggregateId->name,
                $columns->aggregateType,
                $columns->eventType,
                $columns->revision,
                $columns->aggregateVersion,
                $columns->payload,
                $columns->occurredAt
            ),
            parameters: [
                'id'               => $idValue,
                'aggregateId'      => $aggregateIdValue,
                'aggregateType'    => $record->aggregateType,
                'eventType'        => $record->eventType->value,
                'revision'         => $record->revision->value,
                'aggregateVersion' => $record->aggregateVersion->value,
                'payload'          => $payload->toJson(),
                'occurredAt'       => $record->occurredAt->toIso8601()
            ]
        );
    }
}
