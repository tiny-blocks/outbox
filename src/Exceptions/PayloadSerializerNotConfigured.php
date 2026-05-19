<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;

final class PayloadSerializerNotConfigured extends RuntimeException
{
    /**
     * Creates a PayloadSerializerNotConfigured from the event class that has no serializer registered.
     *
     * @param string $eventClass The fully-qualified class name of the unsupported event.
     * @return PayloadSerializerNotConfigured The created instance.
     */
    public static function forEventClass(string $eventClass): PayloadSerializerNotConfigured
    {
        $template = 'No payload serializer configured for event class <%s>.';

        return new PayloadSerializerNotConfigured(message: sprintf($template, $eventClass));
    }
}
