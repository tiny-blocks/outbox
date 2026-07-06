<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use TinyBlocks\BuildingBlocks\Event\DomainEvent;
use TinyBlocks\BuildingBlocks\Event\DomainEventBehavior;

final readonly class InventoryReserved implements DomainEvent
{
    use DomainEventBehavior;

    public function __construct(public string $sku, public int $quantity)
    {
    }

    public function eventType(): string
    {
        return 'InventoryReserved';
    }
}
