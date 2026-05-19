<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;

final class OutboxRequiresActiveTransaction extends RuntimeException
{
    /**
     * Creates an OutboxRequiresActiveTransaction signaling that no transaction was open when push was called.
     *
     * @return OutboxRequiresActiveTransaction The created instance.
     */
    public static function asMissing(): OutboxRequiresActiveTransaction
    {
        return new OutboxRequiresActiveTransaction(
            message: 'push() must be called within an active transaction.'
        );
    }
}
