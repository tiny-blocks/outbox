<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use JsonException;
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

    /**
     * Creates an InvalidPayloadJson from the failure that prevented encoding the payload as JSON.
     *
     * @param JsonException $cause The native error raised while encoding the payload as JSON.
     * @return InvalidPayloadJson The created instance.
     */
    public static function forEncodingFailure(JsonException $cause): InvalidPayloadJson
    {
        $template = 'Payload could not be encoded as JSON: %s.';

        return new InvalidPayloadJson(message: sprintf($template, $cause->getMessage()), previous: $cause);
    }
}
