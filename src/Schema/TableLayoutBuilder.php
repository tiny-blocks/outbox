<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

/**
 * Fluent builder for the outbox table layout.
 */
final class TableLayoutBuilder
{
    private Columns $columns;
    private string $tableName = 'outbox_events';
    private UniqueConstraint $uniqueConstraint;

    private function __construct()
    {
        $this->columns = Columns::default();
        $this->uniqueConstraint = UniqueConstraint::default();
    }

    /**
     * Creates a TableLayoutBuilder seeded with the default table layout.
     *
     * @return TableLayoutBuilder A new builder with the default table layout.
     */
    public static function create(): TableLayoutBuilder
    {
        return new TableLayoutBuilder();
    }

    /**
     * Builds a TableLayout from the configured columns, table name, and unique constraint.
     *
     * @return TableLayout The built table layout.
     */
    public function build(): TableLayout
    {
        return TableLayout::from(
            columns: $this->columns,
            tableName: $this->tableName,
            uniqueConstraint: $this->uniqueConstraint
        );
    }

    /**
     * Sets the column configuration.
     *
     * @param Columns $columns The column configuration to apply.
     * @return TableLayoutBuilder The builder for chaining.
     */
    public function withColumns(Columns $columns): TableLayoutBuilder
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * Sets the outbox table name.
     *
     * @param string $tableName The physical outbox table name.
     * @return TableLayoutBuilder The builder for chaining.
     */
    public function withTableName(string $tableName): TableLayoutBuilder
    {
        $this->tableName = $tableName;
        return $this;
    }

    /**
     * Sets the unique constraint name used to detect duplicate aggregate versions.
     *
     * @param string $name The unique constraint name.
     * @return TableLayoutBuilder The builder for chaining.
     */
    public function withUniqueConstraint(string $name): TableLayoutBuilder
    {
        $this->uniqueConstraint = UniqueConstraint::named(name: $name);
        return $this;
    }
}
