<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use TinyBlocks\Outbox\Schema\IdentityColumn;

final readonly class StringIdentityColumn implements IdentityColumn
{
    private function __construct(private string $name)
    {
    }

    public static function named(string $name): StringIdentityColumn
    {
        return new StringIdentityColumn(name: $name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function convert(mixed $identityValue): string
    {
        return (string)$identityValue;
    }
}
