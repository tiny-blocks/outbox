<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use TinyBlocks\BuildingBlocks\Entity\SingleIdentity;
use TinyBlocks\BuildingBlocks\Entity\SingleIdentityBehavior;

final readonly class OrderId implements SingleIdentity
{
    use SingleIdentityBehavior;

    public function __construct(public string $value)
    {
    }
}
