<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

final readonly class TableLayout
{
    private function __construct(
        public Columns $columns,
        public string $tableName,
        public UniqueConstraint $uniqueConstraint
    ) {
    }

    /**
     * Builds a TableLayout with the explicit columns, table name, and unique constraint.
     *
     * @param Columns $columns The column configuration to apply to the layout.
     * @param string $tableName The physical table name where outbox rows are stored.
     * @param UniqueConstraint $uniqueConstraint The unique constraint guarding aggregate sequencing.
     * @return TableLayout The built table layout.
     */
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

    /**
     * Creates a TableLayoutBuilder used to customize the outbox table layout.
     *
     * @return TableLayoutBuilder A new builder seeded with the default table layout.
     */
    public static function builder(): TableLayoutBuilder
    {
        return TableLayoutBuilder::create();
    }

    /**
     * Creates a TableLayout using the default table name, columns, and unique constraint.
     *
     * @return TableLayout The default table layout.
     */
    public static function default(): TableLayout
    {
        return TableLayoutBuilder::create()->build();
    }
}
