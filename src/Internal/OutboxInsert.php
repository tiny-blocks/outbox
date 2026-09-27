<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventRecord;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Serialization\SerializedPayload;

final readonly class OutboxInsert
{
    private function __construct(public string $sql, public array $parameters)
    {
    }

    public static function from(
        EventRecord|IntegrationEventRecord $record,
        SerializedPayload $payload,
        TableLayout $tableLayout,
        string $correlationId = ''
    ): OutboxInsert {
        $columns = $tableLayout->columns;
        $idValue = $columns->id->convert(identityValue: $record->id->toString());
        $aggregateIdValue = $columns->aggregateId->convert(identityValue: $record->aggregateId->identityValue());

        $names = [
            $columns->id->name(),
            $columns->aggregateId->name(),
            $columns->aggregateType,
            $columns->eventType,
            $columns->revision,
            $columns->aggregateVersion,
            $columns->payload,
            $columns->occurredAt
        ];

        $parameters = [
            'id'               => $idValue,
            'aggregateId'      => $aggregateIdValue,
            'aggregateType'    => $record->aggregateType,
            'eventType'        => $record->eventType->value,
            'revision'         => $record->revision->value,
            'aggregateVersion' => $record->aggregateVersion->value,
            'payload'          => $payload->toJson(),
            'occurredAt'       => $record->occurredAt->toIso8601()
        ];

        if (!is_null($columns->correlationId)) {
            $names[] = $columns->correlationId;
            $parameters['correlationId'] = $correlationId === '' ? null : $correlationId;
        }

        $placeholders = array_map(static fn(string $key): string => sprintf(':%s', $key), array_keys($parameters));

        return new OutboxInsert(
            sql: sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $tableLayout->tableName,
                implode(', ', $names),
                implode(', ', $placeholders)
            ),
            parameters: $parameters
        );
    }
}
