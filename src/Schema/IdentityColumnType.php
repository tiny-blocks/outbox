<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

use TinyBlocks\Outbox\Internal\BinaryIdentityColumn;
use TinyBlocks\Outbox\Internal\StringIdentityColumn;

enum IdentityColumnType: string
{
    case BINARY = 'binary';
    case STRING = 'string';

    /**
     * Returns the IdentityColumn implementation matching this type.
     *
     * @param string $name The column name to assign to the resulting IdentityColumn.
     * @return IdentityColumn The identity column wired to this type and column name.
     */
    public function toColumn(string $name): IdentityColumn
    {
        return match ($this) {
            IdentityColumnType::BINARY => BinaryIdentityColumn::named(name: $name),
            IdentityColumnType::STRING => StringIdentityColumn::named(name: $name)
        };
    }
}
