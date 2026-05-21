<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use TinyBlocks\BuildingBlocks\Event\IntegrationEvent;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventBehavior;

final readonly class RefundCompleted implements IntegrationEvent
{
    use IntegrationEventBehavior;
}
