<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Stringable;

final class DuplicateOutboxEvent extends RuntimeException
{
    public static function forRecord(
        string|int|Stringable $eventId,
        UniqueConstraintViolationException $previous
    ): DuplicateOutboxEvent {
        $template = 'Event with id "%s" already exists in outbox.';

        return new DuplicateOutboxEvent(sprintf($template, (string)$eventId), previous: $previous);
    }
}
