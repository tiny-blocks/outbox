<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

use Ramsey\Uuid\Uuid;
use TinyBlocks\Outbox\Schema\IdentityColumn;

final readonly class BinaryIdentityColumn implements IdentityColumn
{
    private function __construct(private string $name)
    {
    }

    public static function named(string $name): BinaryIdentityColumn
    {
        return new BinaryIdentityColumn(name: $name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function convert(mixed $identityValue): string
    {
        return Uuid::fromString(uuid: (string)$identityValue)->getBytes();
    }
}
