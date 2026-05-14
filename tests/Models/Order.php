<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Models;

use TinyBlocks\BuildingBlocks\Aggregate\EventualAggregateRoot;
use TinyBlocks\BuildingBlocks\Aggregate\EventualAggregateRootBehavior;

final class Order implements EventualAggregateRoot
{
    use EventualAggregateRootBehavior;

    public function __construct(private readonly OrderId $id)
    {
    }

    public static function place(string $orderId): Order
    {
        $order = new Order(id: new OrderId(value: $orderId));
        $order->push(event: new OrderPlaced());
        return $order;
    }

    protected function snapshotState(): array
    {
        return ['status' => 'placed'];
    }
}
