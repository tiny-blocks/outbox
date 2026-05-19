<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;

final class InvalidPayloadJson extends RuntimeException
{
    /**
     * Creates an InvalidPayloadJson from the payload that failed to validate.
     *
     * @param string $payload The raw payload string that is not valid JSON.
     * @return InvalidPayloadJson The created instance.
     */
    public static function forPayload(string $payload): InvalidPayloadJson
    {
        $template = 'Payload is not valid JSON <%s>.';

        return new InvalidPayloadJson(message: sprintf($template, $payload));
    }
}
