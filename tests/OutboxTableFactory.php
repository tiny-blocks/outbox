<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox;

use Doctrine\DBAL\Connection;
use TinyBlocks\Outbox\Schema\TableLayout;

final readonly class OutboxTableFactory
{
    private function __construct()
    {
    }

    public static function createWithBinaryIdentities(Connection $connection, TableLayout $tableLayout): void
    {
        $template = <<<SQL
        CREATE TABLE IF NOT EXISTS %s
        (
            sequence BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
            %s BINARY(16) NOT NULL PRIMARY KEY,
            %s VARCHAR(255) NOT NULL,
            %s BINARY(16) NOT NULL,
            %s VARCHAR(255) NOT NULL,
            %s INT NOT NULL,
            %s BIGINT NOT NULL,
            %s JSON NOT NULL,
            %s JSON NOT NULL,
            %s DATETIME(6) NOT NULL,
            %s DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            INDEX idx_aggregate (%s),
            UNIQUE KEY %s (%s, %s, %s)
        )
        SQL;

        $connection->executeStatement(
            sprintf(
                $template,
                $tableLayout->tableName,
                $tableLayout->columns->id->name,
                $tableLayout->columns->aggregateType,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->columns->eventType,
                $tableLayout->columns->revision,
                $tableLayout->columns->sequenceNumber,
                $tableLayout->columns->payload,
                $tableLayout->columns->snapshot,
                $tableLayout->columns->occurredAt,
                $tableLayout->columns->createdAt,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->uniqueConstraint->name,
                $tableLayout->columns->aggregateType,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->columns->sequenceNumber
            )
        );
    }

    public static function createWithStringIdentities(Connection $connection, TableLayout $tableLayout): void
    {
        $template = <<<SQL
        CREATE TABLE IF NOT EXISTS %s
        (
            sequence BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
            %s VARCHAR(255) NOT NULL PRIMARY KEY,
            %s VARCHAR(255) NOT NULL,
            %s VARCHAR(255) NOT NULL,
            %s VARCHAR(255) NOT NULL,
            %s INT NOT NULL,
            %s BIGINT NOT NULL,
            %s JSON NOT NULL,
            %s JSON NOT NULL,
            %s DATETIME(6) NOT NULL,
            %s DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            INDEX idx_aggregate (%s),
            UNIQUE KEY %s (%s, %s, %s)
        )
        SQL;

        $connection->executeStatement(
            sprintf(
                $template,
                $tableLayout->tableName,
                $tableLayout->columns->id->name,
                $tableLayout->columns->aggregateType,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->columns->eventType,
                $tableLayout->columns->revision,
                $tableLayout->columns->sequenceNumber,
                $tableLayout->columns->payload,
                $tableLayout->columns->snapshot,
                $tableLayout->columns->occurredAt,
                $tableLayout->columns->createdAt,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->uniqueConstraint->name,
                $tableLayout->columns->aggregateType,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->columns->sequenceNumber
            )
        );
    }
}
