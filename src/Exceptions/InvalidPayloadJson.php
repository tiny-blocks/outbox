<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;

final class InvalidPayloadJson extends RuntimeException
{
    public static function for(string $payload): InvalidPayloadJson
    {
        $template = 'Payload is not valid JSON: %s';

        return new InvalidPayloadJson(sprintf($template, $payload));
    }
}
