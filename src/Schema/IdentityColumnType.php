<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

use TinyBlocks\Outbox\Internal\BinaryIdentityColumn;
use TinyBlocks\Outbox\Internal\IdentityColumn;
use TinyBlocks\Outbox\Internal\StringIdentityColumn;

enum IdentityColumnType: string
{
    case BINARY = 'binary';
    case STRING = 'string';

    public function toColumn(string $name): IdentityColumn
    {
        return match ($this) {
            IdentityColumnType::BINARY => BinaryIdentityColumn::named(name: $name),
            IdentityColumnType::STRING => StringIdentityColumn::named(name: $name)
        };
    }
}
