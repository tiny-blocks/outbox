<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Stringable;

final class DuplicateOutboxEvent extends RuntimeException
{
    /**
     * Creates a DuplicateOutboxEvent from the conflicting record id.
     *
     * @param string|int|Stringable $eventId The id of the record that already exists in the outbox.
     * @param UniqueConstraintViolationException $previous The driver-level violation that triggered the failure.
     * @return DuplicateOutboxEvent The created instance.
     */
    public static function forRecord(
        string|int|Stringable $eventId,
        UniqueConstraintViolationException $previous
    ): DuplicateOutboxEvent {
        $template = 'Event with id <%s> already exists in outbox.';

        return new DuplicateOutboxEvent(message: sprintf($template, (string)$eventId), previous: $previous);
    }
}
