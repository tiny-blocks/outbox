<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use TinyBlocks\BuildingBlocks\Event\DomainEvent;
use TinyBlocks\BuildingBlocks\Event\DomainEventBehavior;

final readonly class RefundIssued implements DomainEvent
{
    use DomainEventBehavior;

    public function eventType(): string
    {
        return 'RefundIssued';
    }
}
