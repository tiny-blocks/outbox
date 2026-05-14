<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use TinyBlocks\Outbox\Schema\Columns;
use TinyBlocks\Outbox\Schema\TableLayout;
use TinyBlocks\Outbox\Schema\UniqueConstraint;

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

    public static function create(): TableLayoutBuilder
    {
        return new TableLayoutBuilder();
    }

    public function withColumns(Columns $columns): TableLayoutBuilder
    {
        $this->columns = $columns;
        return $this;
    }

    public function withTableName(string $tableName): TableLayoutBuilder
    {
        $this->tableName = $tableName;
        return $this;
    }

    public function withUniqueConstraint(string $name): TableLayoutBuilder
    {
        $this->uniqueConstraint = UniqueConstraint::named(name: $name);
        return $this;
    }

    public function build(): TableLayout
    {
        return TableLayout::from(
            columns: $this->columns,
            tableName: $this->tableName,
            uniqueConstraint: $this->uniqueConstraint
        );
    }
}
