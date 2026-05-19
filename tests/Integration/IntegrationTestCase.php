<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected static Connection $connection;
    private static array $customTables = [];

    public static function setUpBeforeClass(): void
    {
        self::$connection = Database::instance()->connection();
    }

    protected static function registerTableForCleanup(string $tableName): void
    {
        self::$customTables[] = $tableName;
    }

    protected function tearDown(): void
    {
        if (self::$connection->isTransactionActive()) {
            self::$connection->rollBack();
        }

        foreach (self::$customTables as $tableName) {
            self::$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tableName));
        }

        self::$customTables = [];
    }
}
