<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

use TinyBlocks\Outbox\Internal\TableLayoutBuilder;

final readonly class TableLayout
{
    private function __construct(
        public Columns $columns,
        public string $tableName,
        public UniqueConstraint $uniqueConstraint
    ) {
    }

    public static function from(
        Columns $columns,
        string $tableName,
        UniqueConstraint $uniqueConstraint
    ): TableLayout {
        return new TableLayout(
            columns: $columns,
            tableName: $tableName,
            uniqueConstraint: $uniqueConstraint
        );
    }

    public static function default(): TableLayout
    {
        return TableLayoutBuilder::create()->build();
    }

    public static function builder(): TableLayoutBuilder
    {
        return TableLayoutBuilder::create();
    }
}
