<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;

final class OutboxRequiresActiveTransaction extends RuntimeException
{
    public static function asMissing(): OutboxRequiresActiveTransaction
    {
        return new OutboxRequiresActiveTransaction('push() must be called within an active transaction.');
    }
}
