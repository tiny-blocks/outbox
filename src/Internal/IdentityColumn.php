<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Internal;

abstract readonly class IdentityColumn
{
    final public function __construct(public string $name)
    {
    }

    abstract public function convert(mixed $identityValue): string;
}
