<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;

final class PayloadSerializerNotConfigured extends RuntimeException
{
    public function __construct(string $eventClass)
    {
        $template = 'No payload serializer configured for event class <%s>.';

        parent::__construct(sprintf($template, $eventClass));
    }
}
