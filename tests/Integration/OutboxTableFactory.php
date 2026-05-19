<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Integration;

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
            %s BINARY(16)   NOT NULL,
            %s JSON         NOT NULL,
            %s INT          NOT NULL,
            %s VARCHAR(255) NOT NULL,
            %s TIMESTAMP(6) NOT NULL,
            %s BINARY(16)   NOT NULL,
            %s VARCHAR(255) NOT NULL,
            %s BIGINT       NOT NULL,
            %s TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (%s),
            CONSTRAINT %s UNIQUE (%s, %s, %s)
        ) ENGINE = InnoDB
          DEFAULT CHARSET = utf8mb4
          COLLATE = utf8mb4_0900_ai_ci
        SQL;

        $connection->executeStatement(
            sprintf(
                $template,
                $tableLayout->tableName,
                $tableLayout->columns->id->name,
                $tableLayout->columns->payload,
                $tableLayout->columns->revision,
                $tableLayout->columns->eventType,
                $tableLayout->columns->occurredAt,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->columns->aggregateType,
                $tableLayout->columns->aggregateVersion,
                $tableLayout->columns->createdAt,
                $tableLayout->columns->id->name,
                $tableLayout->uniqueConstraint->name,
                $tableLayout->columns->aggregateType,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->columns->aggregateVersion
            )
        );
    }

    public static function createWithStringIdentities(Connection $connection, TableLayout $tableLayout): void
    {
        $template = <<<SQL
        CREATE TABLE IF NOT EXISTS %s
        (
            %s VARCHAR(255) NOT NULL,
            %s JSON         NOT NULL,
            %s INT          NOT NULL,
            %s VARCHAR(255) NOT NULL,
            %s TIMESTAMP(6) NOT NULL,
            %s VARCHAR(255) NOT NULL,
            %s VARCHAR(255) NOT NULL,
            %s BIGINT       NOT NULL,
            %s TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (%s),
            CONSTRAINT %s UNIQUE (%s, %s, %s)
        ) ENGINE = InnoDB
          DEFAULT CHARSET = utf8mb4
          COLLATE = utf8mb4_0900_ai_ci
        SQL;

        $connection->executeStatement(
            sprintf(
                $template,
                $tableLayout->tableName,
                $tableLayout->columns->id->name,
                $tableLayout->columns->payload,
                $tableLayout->columns->revision,
                $tableLayout->columns->eventType,
                $tableLayout->columns->occurredAt,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->columns->aggregateType,
                $tableLayout->columns->aggregateVersion,
                $tableLayout->columns->createdAt,
                $tableLayout->columns->id->name,
                $tableLayout->uniqueConstraint->name,
                $tableLayout->columns->aggregateType,
                $tableLayout->columns->aggregateId->name,
                $tableLayout->columns->aggregateVersion
            )
        );
    }
}
