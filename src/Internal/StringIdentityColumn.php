<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

final readonly class StringIdentityColumn extends IdentityColumn
{
    public static function named(string $name): StringIdentityColumn
    {
        return new StringIdentityColumn(name: $name);
    }

    public function convert(mixed $identityValue): string
    {
        return (string)$identityValue;
    }
}
