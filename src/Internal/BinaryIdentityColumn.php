<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use Ramsey\Uuid\Uuid;

final readonly class BinaryIdentityColumn extends IdentityColumn
{
    public static function named(string $name): BinaryIdentityColumn
    {
        return new BinaryIdentityColumn(name: $name);
    }

    public function convert(mixed $identityValue): string
    {
        return Uuid::fromString(uuid: (string)$identityValue)->getBytes();
    }
}
