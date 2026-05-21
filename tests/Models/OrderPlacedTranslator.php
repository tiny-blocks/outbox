<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use TinyBlocks\BuildingBlocks\Event\EventRecord;
use TinyBlocks\BuildingBlocks\Event\IntegrationEvent;
use TinyBlocks\BuildingBlocks\Event\IntegrationEventTranslator;

final readonly class OrderPlacedTranslator implements IntegrationEventTranslator
{
    public function supports(EventRecord $record): bool
    {
        return $record->event instanceof OrderPlaced;
    }

    public function translate(EventRecord $record): IntegrationEvent
    {
        return new OrderShipped();
    }
}
