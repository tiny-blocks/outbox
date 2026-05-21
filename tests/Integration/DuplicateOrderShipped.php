<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Integration;

use TinyBlocks\BuildingBlocks\Event\IntegrationEvent;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventBehavior;

final readonly class DuplicateOrderShipped implements IntegrationEvent
{
    use IntegrationEventBehavior;
}
